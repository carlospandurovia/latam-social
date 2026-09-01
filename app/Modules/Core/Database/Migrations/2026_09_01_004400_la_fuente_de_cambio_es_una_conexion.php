<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La fuente de tipos de cambio deja de tener su propia caja fuerte (9.17h).
 *
 * Es la última de las tres que el negocio pidió mover a la base: *«Mover las
 * tres a la base ahora»*. Las otras dos fueron el correo (`9.17g`) y la conexión
 * de facturación (`9.17d`).
 *
 * ### Qué se mueve, y qué NO
 *
 * Lo que se mueve es **la integración**: con quién se habla, a dónde, y con qué
 * clave. Lo que **se queda** en la pantalla de Tipos de cambio son las tasas,
 * las fuentes oficiales por par, el registro de cada traída y la carga a mano:
 * eso no es configuración de una integración, es el trabajo diario con los
 * tipos de cambio (`DEC-265`).
 *
 * ### Por qué `fx_sources` no puede quedarse con la clave
 *
 * Tenía **su propia caja fuerte**: `api_key_cipher`, `api_key_last4`,
 * `credential_set_at` y `credential_set_by_user_id`. Funciona, pero es un
 * segundo almacén de secretos **más pobre que el que ya existe**: no versiona,
 * no revoca, y no deja el rastro de la anterior. `integration_credentials`
 * (`9.17d`) hace las tres cosas, y `DEC-257` dice exactamente esto: el
 * esqueleto —cifrar, ROTAR, auditar— se comparte; lo propio del propósito se
 * queda en su tabla. Lo propio de una fuente es su código, su nombre y si está
 * en uso. La clave nunca lo fue (`DEC-266`).
 *
 * ### El secreto se muda SIN pasar por texto claro
 *
 * Las dos cajas cifran con `Crypt::encryptString`, así que el criptograma se
 * copia **tal cual**. Esta migración no descifra nada: no hay ningún instante
 * en el que la clave exista en claro en memoria, y no hace falta `APP_KEY`
 * válida para que la mudanza salga bien.
 *
 * ### Se puede volver a correr
 *
 * Cada paso comprueba si ya está hecho. **No es una precaución teórica:** la
 * primera pasada de esta migración en la instalación real se rompió a la mitad
 * —ver abajo— y dejó la columna puesta y las viejas también. Una migración que
 * sólo funciona sobre una base intacta obliga a arreglar a mano justo cuando
 * algo acaba de salir mal.
 */
return new class extends Migration
{
    /** La dirección pública de Decolecta. Fija, y de todo el mundo. */
    private const URL_DECOLECTA = 'https://api.decolecta.com';

    public function up(): void
    {
        if (!Schema::hasColumn('fx_sources', 'integration_connection_id')) {
            Schema::table('fx_sources', function (Blueprint $tabla): void {
                $tabla->unsignedBigInteger('integration_connection_id')->nullable()->after('description');
                $tabla->unique('integration_connection_id', 'uq_fxs_conexion');
                $tabla->foreign('integration_connection_id', 'fk_fxs_conn')
                    ->references('id')->on('integration_connections')->restrictOnDelete();
            });
        }

        $this->sembrarExtremo();
        $this->mudarLasClaves();
        $this->tirarLaCajaFuerteVieja();
    }

    /**
     * La dirección del proveedor, por si el catálogo ya existe.
     *
     * En una base recién creada el proveedor todavía no está --lo siembra
     * `CimientosSeeder` después--, y entonces esto no hace nada: el seeder lo
     * siembra también. La migración no puede DEPENDER del seeder ni al revés.
     */
    private function sembrarExtremo(): void
    {
        $proveedorId = $this->proveedor();

        if ($proveedorId === null) {
            return;
        }

        $existe = DB::table('integration_provider_endpoints')
            ->where('integration_provider_id', $proveedorId)
            ->where('environment', 'production')->exists();

        if ($existe) {
            return;
        }

        DB::table('integration_provider_endpoints')->insert([
            'integration_provider_id' => $proveedorId,
            'environment' => 'production',
            'base_url' => self::URL_DECOLECTA,
            'label' => 'API de Decolecta',
            'notes' => 'Publica el tipo de cambio de SUNAT. Solo USD-PEN, compra y venta.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Cada fuente que tenga clave guardada estrena conexión y credencial.
     *
     * El criptograma se copia tal cual: ver la cabecera. Si la fuente no tenía
     * ninguna clave no se le inventa una conexión --se creará la primera vez
     * que alguien guarde desde la pestaña--, porque una conexión activa sin
     * credencial es justo lo que la pantalla de `9.17d` avisa en rojo.
     */
    private function mudarLasClaves(): void
    {
        $proveedorId = $this->proveedor();

        if ($proveedorId === null || !Schema::hasColumn('fx_sources', 'api_key_cipher')) {
            return;
        }

        $fuentes = DB::table('fx_sources')
            ->whereNull('integration_connection_id')
            ->whereNotNull('api_key_cipher')->where('api_key_cipher', '<>', '')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'api_base_url', 'api_key_cipher', 'api_key_last4',
                'credential_set_at', 'credential_set_by_user_id']);

        // `uq_iconn_activa` solo admite UNA activa por proposito y entorno
        // (`DEC-258`). Hoy solo hay una fuente, pero si alguna instalacion
        // tuviera dos con clave, la primera queda en uso y las demas apagadas:
        // apagada conserva su clave y se puede encender, que es lo que 9.17i
        // dejo hecho para el correo.
        $primera = DB::table('integration_connections')
            ->where('purpose_snapshot', 'fx')->where('status', 'active')->doesntExist();

        foreach ($fuentes as $fuente) {
            $autor = $fuente->credential_set_by_user_id
                ?? DB::table('users')->orderBy('id')->value('id');

            if ($autor === null) {
                // Sin ningun usuario en la base no hay a quien atribuir la
                // credencial, y `set_by_user_id` no admite nulo. Se deja la
                // clave donde esta: la columna aun no se ha tirado.
                continue;
            }

            $conexionId = (int) DB::table('integration_connections')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'integration_provider_id' => $proveedorId,
                'legal_entity_id' => null,
                'name' => mb_substr($fuente->name.' — tipos de cambio', 0, 120),
                'environment' => 'production',
                'base_url' => $this->urlPropia($fuente->api_base_url, $proveedorId),
                'username' => null,
                'status' => $primera ? 'active' : 'disabled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('integration_credentials')->insert([
                'integration_connection_id' => $conexionId,
                'kind' => 'api_key',
                // TAL CUAL. Aqui no se descifra nada.
                'secret_cipher' => (string) $fuente->api_key_cipher,
                'last4' => $fuente->api_key_last4,
                'version' => 1,
                'set_by_user_id' => (int) $autor,
                'set_at' => $fuente->credential_set_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fx_sources')->where('id', $fuente->id)->update([
                'integration_connection_id' => $conexionId,
                'updated_at' => now(),
            ]);

            $primera = false;
        }
    }

    /**
     * Qué URL se arrastra a la conexión, y cuál NO.
     *
     * **La primera pasada real se rompió justo aquí, y el valor que la rompió
     * es el mejor argumento de `DEC-255` que ha dado este proyecto:** la columna
     * decía `http://api.decolecta.com`. Alguien tecleó a mano una dirección que
     * es una constante, y se le fue el segundo carácter. El disparador de
     * `9.17g` lo paró —«una direccion web sin cifrar manda las claves en
     * claro»—, que es exactamente lo que estaba pasando: la clave de la API iba
     * en la cabecera `Authorization`, por HTTP, cada madrugada.
     *
     * Así que:
     *
     * - **Mismo servidor que el del catálogo** → se descarta y manda la del
     *   proveedor. `http://api.decolecta.com` y `https://api.decolecta.com` son
     *   la misma dirección escrita mal, y la buena ya está declarada.
     * - **Otro servidor, con `https`** → se conserva: es una excepción legítima.
     * - **Otro servidor, sin cifrar** → **no se arrastra.** Traerla sería
     *   hacerse cargo de seguir mandando la clave en claro, y ascenderla a
     *   `https` por mi cuenta sería adivinar que ese servidor escucha ahí. Se
     *   queda la del proveedor y quien administre lo corrige viéndolo.
     */
    private function urlPropia(?string $url, int $proveedorId): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $delProveedor = (string) (DB::table('integration_provider_endpoints')
            ->where('integration_provider_id', $proveedorId)
            ->where('environment', 'production')
            ->value('base_url') ?: self::URL_DECOLECTA);

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === false || $host === null || $host === parse_url($delProveedor, PHP_URL_HOST)) {
            return null;
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Y se tira la caja fuerte vieja, con su disparador y su regla.
     *
     * Se tira y no se deja «por si acaso»: dos sitios donde puede estar la
     * clave es la forma de que un dia la lea de uno y se guarde en el otro.
     */
    private function tirarLaCajaFuerteVieja(): void
    {
        if (!Schema::hasColumn('fx_sources', 'api_key_cipher')) {
            return;
        }

        // Quedaria una clave sin mudar --por ejemplo, una base sin ningun
        // usuario--. Tirar la columna ahora la perderia.
        $sinMudar = DB::table('fx_sources')
            ->whereNull('integration_connection_id')
            ->whereNotNull('api_key_cipher')->where('api_key_cipher', '<>', '')
            ->exists();

        if ($sinMudar) {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS `tg_fxs_credencial_firmada`');
        Restriccion::quitar('fx_sources', 'ck_fxs_last4');

        Schema::table('fx_sources', function (Blueprint $tabla): void {
            $tabla->dropForeign('fk_fxs_credencial');
            $tabla->dropIndex('ix_fxs_credencial');
        });

        Schema::table('fx_sources', function (Blueprint $tabla): void {
            $tabla->dropColumn([
                'api_key_cipher', 'api_key_last4',
                'credential_set_at', 'credential_set_by_user_id',
                'api_base_url',
            ]);
        });
    }

    private function proveedor(): ?int
    {
        $id = DB::table('integration_providers')->where('code', 'decolecta')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function down(): void
    {
        Schema::table('fx_sources', function (Blueprint $tabla): void {
            $tabla->dropForeign('fk_fxs_conn');
            $tabla->dropUnique('uq_fxs_conexion');
            $tabla->dropColumn('integration_connection_id');
        });
    }
};
