<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué credenciales necesita cada proveedor (`L-3b`, cierra `T-132`).
 *
 * ### El agujero que cierra
 *
 * El formulario de credencial enseñaba las CINCO clases del catálogo —clave de
 * API, contraseña, token, secreto de webhook, secreto de cliente— a todos los
 * proveedores por igual. Para SUNAT sólo sirve una: `password`, que es la clave
 * SOL del usuario secundario, y es la que `Comprobantes` pide por su nombre.
 *
 * Lo caro no era ofrecer de más. Era que el aviso rojo de «sin credencial»
 * comprobaba que existiera **alguna** credencial viva, sin mirar la clase. Así
 * que guardar la clave SOL como «Clave de API» dejaba la pantalla en verde, la
 * conexión parecía configurada, y la emisión se estrellaba al primer
 * comprobante. Es exactamente el modo de fallo que ese aviso dice combatir,
 * reintroducido por el desplegable de al lado.
 *
 * ### Por qué es una tabla y no un `match` en el código
 *
 * `DEC-190`. El día que se añada un OSE, o una pasarela de pagos, o el emisor
 * de otro país, decir qué credenciales pide **no puede ser un despliegue**. El
 * código pone la regla —«sólo se ofrece lo que este proveedor declara»— y el
 * dato pone la lista.
 *
 * Y una tabla y no una columna con una lista separada por comas porque cada
 * clase necesita cosas propias: **su etiqueta de verdad** —«Clave SOL del
 * usuario secundario» dice mucho más que «Contraseña»—, si es obligatoria, y en
 * qué orden se enseña. Una lista CSV obligaría a inventar todo eso en la
 * plantilla, que es donde no debe vivir.
 *
 * ### Quien siembra esto de verdad es `CimientosSeeder`, no esta migración
 *
 * Las migraciones corren **antes** que el sembrador, así que en una base limpia
 * `integration_providers` todavía está vacía cuando esto pasa: el bucle de abajo
 * no encuentra ningún proveedor y no siembra nada, **en silencio**. En
 * producción sí funcionó --los proveedores ya existían-- y en la base de pruebas
 * quedó vacía; tres pruebas se pusieron rojas diciendo exactamente eso. Es la
 * misma lección de `T-120`, otra vez: **una migración no puede sembrar sobre un
 * catálogo que siembra el seeder**.
 *
 * El sembrado de verdad vive en `CimientosSeeder`, junto a los proveedores. Lo
 * de aquí abajo se queda porque hace falta para las bases que **ya** están
 * sembradas y no van a volver a pasar por el seeder --producción, sin ir más
 * lejos--. Los dos son idempotentes y escriben lo mismo.
 *
 * ### Un proveedor sin declarar NO bloquea nada
 *
 * Si nadie ha declarado sus clases, el formulario vuelve al catálogo completo y
 * lo dice en ámbar. Un proveedor recién añadido tiene que poder recibir su clave
 * el mismo día, no cuando alguien se acuerde de rellenar esta tabla (`DEC-190`:
 * un aviso con prioridad, nunca un stopper).
 */
return new class extends Migration
{
    /**
     * Lo que declara cada proveedor sembrado, medido contra quien lo consume.
     *
     * `sunat` → `password`: `Comprobantes::credenciales()` lee exactamente
     * `Integraciones::secreto($id, 'password')`.
     * `smtp` → `password`: `CuentaDeCorreo` hace lo mismo para `mail.mailers.smtp.password`.
     * `decolecta` → `api_key`: es una API de clave en cabecera.
     *
     * @return list<array{0:string,1:string,2:string,3:bool,4:int}>
     */
    private static function declaraciones(): array
    {
        return [
            ['sunat', 'password', 'Clave SOL del usuario secundario', true, 10],
            ['smtp', 'password', 'Contraseña de la cuenta de correo', true, 10],
            ['decolecta', 'api_key', 'Clave de API', true, 10],
        ];
    }

    public function up(): void
    {
        if (!Schema::hasTable('integration_providers')) {
            return;
        }

        if (!Schema::hasTable('integration_provider_credentials')) {
            Schema::create('integration_provider_credentials', function (Blueprint $tabla): void {
                $tabla->id();
                $tabla->foreignId('integration_provider_id');
                // La clase, del mismo catalogo que `integration_credentials.kind`:
                // esto declara CUAL de las que existen pide el proveedor, no
                // inventa clases nuevas.
                $tabla->string('kind', 30);
                // La etiqueta que lee una persona. «Clave SOL del usuario
                // secundario» y no «Contrasena»: quien la carga tiene que saber
                // CUAL de las cuatro claves que tiene delante es.
                $tabla->string('label', 120);
                $tabla->string('help', 255)->nullable();
                $tabla->boolean('is_required')->default(true);
                $tabla->unsignedSmallInteger('sort_order')->default(10);
                $tabla->dateTime('created_at', 3)->nullable();
                $tabla->dateTime('updated_at', 3)->nullable();

                // Un proveedor no pide la misma clase dos veces.
                $tabla->unique(['integration_provider_id', 'kind'], 'uq_iprovcred_clase');

                // RESTRICT como todo lo demas de este modulo: aqui nada se borra
                // en cascada.
                $tabla->foreign('integration_provider_id', 'fk_iprovcred_prov')
                    ->references('id')->on('integration_providers')->restrictOnDelete();
            });
        }

        Restriccion::comprobacion(
            tabla: 'integration_provider_credentials',
            nombre: 'ck_iprovcred_kind',
            expresion: "kind IN ('api_key','password','token','webhook_secret','client_secret')",
            columnas: ['kind'],
            mensaje: 'Clase de credencial no valida.',
        );

        Restriccion::comprobacion(
            tabla: 'integration_provider_credentials',
            nombre: 'ck_iprovcred_label',
            expresion: "TRIM(label) <> ''",
            columnas: ['label'],
            mensaje: 'Una credencial declarada sin etiqueta no le dice nada a quien la carga.',
        );

        $ahora = now();

        foreach (self::declaraciones() as [$codigo, $clase, $etiqueta, $obligatoria, $orden]) {
            $proveedorId = DB::table('integration_providers')->where('code', $codigo)->value('id');

            if ($proveedorId === null) {
                // Ni un fallo ni un caso raro: en una base LIMPIA esto pasa
                // siempre, porque el sembrador va despues. Quien lo cubre es
                // `CimientosSeeder`; esto es solo para las bases ya sembradas.
                continue;
            }

            $ya = DB::table('integration_provider_credentials')
                ->where('integration_provider_id', $proveedorId)
                ->where('kind', $clase)->exists();

            if ($ya) {
                continue;
            }

            DB::table('integration_provider_credentials')->insert([
                'integration_provider_id' => $proveedorId,
                'kind' => $clase,
                'label' => $etiqueta,
                'is_required' => $obligatoria,
                'sort_order' => $orden,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Restriccion::quitar('integration_provider_credentials', 'ck_iprovcred_label');
        Restriccion::quitar('integration_provider_credentials', 'ck_iprovcred_kind');
        Schema::dropIfExists('integration_provider_credentials');
    }
};
