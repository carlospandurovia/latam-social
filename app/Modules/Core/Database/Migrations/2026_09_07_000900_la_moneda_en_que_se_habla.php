<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En qué moneda habla el panel cuando hay varias (`D-12`).
 *
 * ### El problema que cierra
 *
 * `D-8` dejó el bloque financiero **por moneda y sin sumar**, y lo dejó a
 * propósito: sumar soles con dólares es la mentira más cara que puede contar un
 * panel. Para consolidar hacen falta dos cosas y ninguna es técnica: **en qué
 * moneda se presenta el total** y **con qué lado de la tasa se convierte**.
 *
 * ### Por qué es una tabla y no `'PEN'` en el código
 *
 * Porque este sistema es white label (`DEC-190`). La instalación de mañana
 * factura en México y su panel tiene que hablar en pesos sin que nadie despliegue
 * nada. El código pone la REGLA —convertir cada moneda a la base con la tasa del
 * día y decir con cuál— y la configuración pone el VALOR.
 *
 * ### Nace sembrada, sin moneda, y aun así el panel consolida
 *
 * La fila entra aquí con los dos lados de partida y **sin moneda**: cuando esta
 * migración corre, el catálogo de monedas todavía no existe --lo siembra
 * `CimientosSeeder` después--, así que escribir `'PEN'` aquí sería una foránea
 * rota y la migración se caería (`T-120`). Quien contesta mientras tanto es
 * `Consolidacion::PARTIDA`, y por eso el panel consolida desde el primer minuto.
 * `confirmed_at` nace **NULL**: nadie ha dicho todavía que ésa sea la moneda del
 * negocio. Mientras siga en NULL la
 * pantalla de configuración lo dice en ámbar --y el panel lo dice debajo del
 * total--. No bloquea nada, que es lo que `DEC-190` exige de verdad: es un aviso
 * con prioridad, no un stopper.
 *
 * ### Los dos lados, y por qué son dos
 *
 * SUNAT publica compra y venta. La regla contable peruana usa **compra** para
 * los ingresos y **venta** para los egresos, y por eso son dos columnas y no
 * una: consolidar lo facturado y lo pagado a creadores con la misma tasa sería
 * elegir a cuál de los dos números mentirle. `Cambio` no tiene lado por defecto
 * a propósito (`Q-63`); esta tabla es la que contesta esa pregunta **para el
 * panel**, y sólo para el panel.
 *
 * ### Lo que este total NO es
 *
 * No es un número contable. Convierte un AGREGADO con una sola tasa --la del
 * cierre del periodo-- mientras que cada documento lleva la suya congelada
 * dentro. Sirve para leer una pantalla, no para declarar. Queda escrito en
 * `T-118` y la propia pantalla lo dice.
 */
return new class extends Migration
{
    /**
     * Los valores de partida que SÍ puede escribir una migración.
     *
     * La moneda no está aquí, y no es un olvido: `currencies` la puebla
     * `CimientosSeeder`, que corre DESPUÉS de las migraciones. Sembrar `'PEN'`
     * en esta fila apuntaría con una foránea a un catálogo todavía vacío y la
     * migración se caería con un `1452` --medido, no supuesto (`T-120`)--.
     * La columna nace NULL y el valor de partida lo pone
     * `Consolidacion::PARTIDA`, que es donde tiene que estar: la base dice
     * «nadie ha elegido» y el código dice «mientras tanto, PEN».
     */
    private const PARTIDA = [
        'income_rate_side' => 'buy',
        'expense_rate_side' => 'sell',
    ];

    public function up(): void
    {
        Schema::create('consolidation_settings', function (Blueprint $table): void {
            $table->id();

            // La puerta: vale 1 y solo puede haber una. Dos filas son dos
            // verdades, y el panel ensenaria la que devuelva el motor primero.
            $table->unsignedTinyInteger('singleton')->default(1);

            // En que moneda se presenta el total. `char(3)` y con foranea al
            // catalogo: una moneda inventada aqui produciria un panel que no
            // convierte nada y no sabria decir por que.
            //
            // NULLABLE y sin valor por defecto: cuando esta migracion corre, el
            // catalogo de monedas todavia no existe --lo siembra
            // `CimientosSeeder` despues-- asi que cualquier valor aqui seria una
            // foranea rota. NULL significa «nadie ha elegido», y quien contesta
            // mientras tanto es `Consolidacion::PARTIDA`.
            $table->char('base_currency_code', 3)->nullable();

            // Con que lado se convierte lo que ENTRA y lo que SALE. Ver arriba.
            $table->string('income_rate_side', 4)->default('buy');
            $table->string('expense_rate_side', 4)->default('sell');

            // Cuando una persona confirmo que esto es lo que el negocio quiere.
            // NULL = sembrado y sin confirmar, que es un aviso ambar y no un
            // bloqueo.
            $table->dateTime('confirmed_at', 3)->nullable();

            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('singleton', 'uq_cs_unica');
            $table->index('base_currency_code', 'ix_cs_moneda');
            $table->index('updated_by_user_id', 'ix_cs_usuario');

            $table->foreign('base_currency_code', 'fk_cs_moneda')
                ->references('code')->on('currencies')->restrictOnDelete();

            $table->foreign('updated_by_user_id', 'fk_cs_usuario')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Sembrada --la fila existe-- pero SIN moneda: ver el bloque de
        // `PARTIDA`. El panel consolida igual desde el primer minuto, que es lo
        // que `DEC-190` exige; lo que no hace es fingir una eleccion que nadie
        // ha hecho.
        DB::table('consolidation_settings')->insert(self::PARTIDA + [
            'singleton' => 1,
            'base_currency_code' => null,
            'confirmed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('consolidation_settings');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['consolidation_settings', 'ck_cs_unica', 'singleton = 1', ['singleton'],
                'La configuracion de consolidacion es una sola fila.'],

            // Los tres lados que `Cambio` sabe leer. Un cuarto valor no daria
            // un error: daria un panel que no encuentra tasa nunca y culpa a la
            // tabla de tipos de cambio.
            ['consolidation_settings', 'ck_cs_lado_ingreso',
                "income_rate_side IN ('buy','sell','mid')", ['income_rate_side'],
                'El lado de la tasa para ingresos es compra, venta o medio.'],

            ['consolidation_settings', 'ck_cs_lado_egreso',
                "expense_rate_side IN ('buy','sell','mid')", ['expense_rate_side'],
                'El lado de la tasa para egresos es compra, venta o medio.'],
        ];
    }
};
