<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Models\User;
use App\Shared\Auth\Permisos;
use App\Shared\Crypto\CuentaBancaria;
use App\Shared\Database\Vigencia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que toda prueba necesita antes de poder probar nada (`T-13`).
 *
 * ### Por qué existe
 *
 * `usuarioCon()` estaba copiado **dieciséis veces en quince archivos**. Los
 * `insert` de creador, en diez sitios de siete. Cada restricción nueva los deja
 * obsoletos de uno en uno, y el aviso llega como «14 failed» en la máquina de
 * quien recibe la entrega, sin decir cuál es la premisa que falta.
 *
 * Pero lo caro no era escribirlos: era **saber qué escribir**. Un creador
 * `active` no es una palabra que se teclee. Son cuatro cosas a la vez:
 *
 * | Restricción | Qué exige |
 * |---|---|
 * | `ck_creators_activation` | `activated_at` |
 * | `ck_creators_active_identity` | `identity_verified_at` |
 * | `ck_creators_identity_evidence` | quién lo verificó **y** el archivo del documento |
 * | `fk_creators_identity_file` | que ese archivo exista en `files` |
 *
 * Hasta ahora eso se descubría a base de errores `4025`, uno por intento. Por
 * eso `creadorActivo()` es el método que justifica este archivo: no ahorra
 * teclas, ahorra los cuatro intentos fallidos.
 *
 * ### Lo que NO hace
 *
 * No monta los seis requisitos de la activación por pantalla —red social
 * verificada, perfil fiscal aprobado, medio de pago verificado, términos
 * aceptados—. `creadorActivo()` escribe un creador que la **base** acepta como
 * activo, que es otra cosa. Quien pruebe la activación tiene que recorrerla, y
 * eso es exactamente lo que hace `ActivacionCreadorTest`: si este apoyo se la
 * diera hecha, esa prueba dejaría de probar lo que dice.
 */
trait ConFixturas
{
    /**
     * Un usuario con un rol, o **sin ninguno** si se pasa `null`.
     *
     * El `?string` viene de `PermisosTest`, que era la única de las dieciséis
     * copias que lo admitía. Un usuario sin rol no es un caso raro: es el que
     * comprueba que una pantalla protegida rechaza a quien no tiene permiso, y
     * catorce copias no podían expresarlo.
     */
    protected function usuarioCon(?string $rol): User
    {
        $usuario = User::factory()->create();

        if ($rol !== null) {
            $rolId = DB::table('roles')->where('code', $rol)->value('id');

            if ($rolId === null) {
                // Sin esto el `insert` falla con un `1048` sobre `role_id` y el
                // mensaje acusa a la tabla en vez de al rol mal escrito. La
                // premisa se comprueba, no se supone.
                $this->fail("El rol '{$rol}' no existe. ¿Falta sembrar `CimientosSeeder`?");
            }

            DB::table('role_user')->insert([
                'user_id' => $usuario->id,
                'role_id' => $rolId,
                'assigned_at' => now(),
            ]);
        }

        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }

    /**
     * Un creador `pending`, que es el mínimo que la base acepta.
     *
     * `pending` y no `active` **a propósito**: una prueba que no va de
     * activación no debe pagar el precio de un creador activo. Un fixture que
     * declara más de lo que la prueba usa es un fixture que se rompe por
     * motivos ajenos a la prueba.
     *
     * @param array<string, mixed> $cambios
     */
    protected function creadorPendiente(array $cambios = []): int
    {
        return (int) DB::table('creators')->insertGetId(self::datosDeCreador($cambios));
    }

    /**
     * Un creador que la base acepta como `active`, con su evidencia.
     *
     * **Éste es el método por el que existe este archivo.** Las cuatro reglas
     * de arriba se cumplen aquí, una vez, y con el archivo de identidad creado
     * de verdad en `files` — porque `identity_document_file_id` es una foránea
     * y un id inventado da un `1452` que no dice nada de lo que falta.
     *
     * Devuelve el id del creador. El revisor se crea solo; si hace falta
     * nombrarlo, se pasa uno.
     *
     * @param array<string, mixed> $cambios
     */
    protected function creadorActivo(array $cambios = [], ?int $revisorId = null): int
    {
        // `campaign_manager` y no un rol inventado: los seis roles del sistema
        // son admin, campaign_manager, finance, content_reviewer, client_user y
        // creador. Escribir `creator_manager` --que suena bien y no existe-- fue
        // lo primero que hizo saltar la comprobacion de `usuarioCon()`, y eso
        // es exactamente para lo que esta.
        $revisorId ??= (int) $this->usuarioCon('campaign_manager')->id;

        return $this->creadorPendiente(array_merge([
            'status' => 'active',
            'activated_at' => now(),
            'identity_verified_at' => now(),
            'identity_verified_by_user_id' => $revisorId,
            'identity_document_file_id' => $this->archivoDeIdentidad(),
        ], $cambios));
    }

    /** Una fila de `files` con pinta de documento de identidad. */
    protected function archivoDeIdentidad(string $nombre = 'dni.pdf'): int
    {
        return (int) DB::table('files')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'disk' => 'local',
            'path' => 'pruebas/'.$nombre,
            'original_name' => $nombre,
            'mime_type' => 'application/pdf',
            // No cero: `ck_files_size` lo rechaza, y ese fue el sintoma del
            // fallo de `UploadedFile::fake()->create()` en Windows.
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $nombre),
            'visibility' => 'private',
            'purpose' => 'identity_document',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un PDF de mentira pero con **bytes de verdad**.
     *
     * `UploadedFile::fake()->create()` dimensiona el temporal con `ftruncate`, y
     * en Windows ese archivo se copiaba vacío: `Almacen` guardaba
     * `size_bytes = 0` y `ck_files_size` devolvía un 500. Con contenido escrito
     * de verdad, la prueba comprueba lo mismo en los dos sistemas.
     */
    protected function pdfDePrueba(string $nombre = 'dni.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $nombre,
            "%PDF-1.4\n% contenido de prueba\n%%EOF\n",
        );
    }

    /**
     * Publica una versión de los términos, cerrando la anterior **el día antes**.
     *
     * Ésta era la **undécima** copia del defecto de `H-16`: cerraba con
     * `CarbonImmutable::parse($desde)->subDay()` escrito a mano, fuera de
     * `Vigencia`. La puerta `vigencias` no la veía porque sólo mira `app/`.
     *
     * Que estuviera bien calculada no la salvaba: lo que hace peligroso a este
     * código es que **simula** `PublicarTerminosCommand`, y una simulación que
     * puede desviarse del original prueba el original de mentira.
     */
    protected function publicarTerminos(string $version = '2026.1', string $desde = '2026-01-01'): int
    {
        DB::table('terms_versions')
            ->where('code', 'creator_terms')
            ->whereNull('effective_to')
            ->update(['effective_to' => Vigencia::cerrarElDiaAntesDe($desde)]);

        return (int) DB::table('terms_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'audience' => 'creator',
            'code' => 'creator_terms',
            'version' => $version,
            'title' => 'Terminos del creador '.$version,
            'body' => 'Texto de prueba.',
            'content_sha256' => hash('sha256', 'Texto de prueba.'.$version),
            'effective_from' => $desde,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un medio de pago escrito directamente, con sus cuatro reglas atendidas.
     *
     * Escribir uno a mano cuesta tres errores `3819` seguidos, descubiertos de
     * uno en uno — que es literalmente lo que `T-13` decía. Aquí están las
     * cuatro, una vez:
     *
     * | Restricción | Qué exige |
     * |---|---|
     * | `ck_cpm_status` | `pending`, `verified`, `rejected` o `disabled` — **no** `inactive` |
     * | `ck_cpm_default_usable` | un predeterminado tiene que estar `verified` |
     * | `ck_cpm_verified` | y verificado exige verificador **y** fecha |
     * | `ck_cpm_closed` | retirarlo exige decir **quién y cuándo** |
     *
     * Se escribe directo y no por el controlador **a propósito**: quien lo usa
     * suele necesitar un estado que la aplicación no produce —una huella
     * desfasada tras rotar `APP_KEY`, por ejemplo—. Para el camino normal está
     * la pantalla, y `MediosPagoTest` la recorre.
     *
     * @param array<string, mixed> $cambios
     */
    protected function medioDePago(int $creadorId, string $cuenta, array $cambios = []): int
    {
        $estado = (string) ($cambios['status'] ?? 'pending');
        $cerrado = in_array($estado, ['rejected', 'disabled'], true);
        $verificado = $estado === 'verified';
        $quien = fn (): int => (int) $this->usuarioCon('finance')->id;

        return (int) DB::table('creator_payment_methods')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'creator_id' => $creadorId,
            'owner_type' => 'creator',
            'method_type' => 'bank_account',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'currency_code' => 'PEN',
            'bank_name' => 'BCP',
            'account_type' => 'savings',
            // Dos cifrados del mismo numero salen DISTINTOS: `Crypt` usa un
            // vector de inicializacion aleatorio. Lo que los relaciona es la
            // huella, y por eso la huella es la que detecta cuentas repetidas.
            'account_number_encrypted' => CuentaBancaria::cifrar($cuenta),
            'account_number_masked' => CuentaBancaria::mascara($cuenta),
            'account_number_fingerprint' => CuentaBancaria::huella($cuenta),
            'holder_name' => 'Ana Torres',
            'holder_document_type' => 'DNI',
            'holder_document_number' => '40000001',
            'created_by_user_id' => $quien(),
            'status' => $estado,
            // Verificador y capturador tienen que ser DISTINTOS
            // (`ck_cpm_segregation`): es la separacion de funciones del dinero.
            'verified_at' => $verificado ? now()->subDay() : null,
            'verified_by_user_id' => $verificado ? $quien() : null,
            'closed_at' => $cerrado ? now() : null,
            'closed_by_user_id' => $cerrado ? $quien() : null,
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    /**
     * Una sociedad del grupo, con las columnas que la base exige.
     *
     * `platform_brand_id` es obligatoria y **no tiene valor por omisión**:
     * escribir un `legal_entities` sin ella da un `1364` que habla de un campo
     * cuyo nombre no sugiere nada. Una sociedad pertenece a una marca de
     * plataforma —LATAM Social— y esa es la que hay.
     *
     * No declara cobertura: cubrir un país es una decisión con vigencia
     * (`4.5`), y dársela hecha a quien pida una sociedad sería decidir por él.
     *
     * @param array<string, mixed> $cambios
     */
    protected function entidadLegal(array $cambios = []): int
    {
        return (int) DB::table('legal_entities')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'platform_brand_id' => DB::table('platform_brands')->value('id'),
            'code' => 'SOC-'.mb_substr((string) Str::uuid(), 0, 6),
            'legal_name' => 'Sociedad de prueba SAC',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'tax_id_type' => 'RUC',
            'tax_id_number' => (string) random_int(20000000000, 20999999999),
            'address_line1' => 'Av Siempre Viva 100',
            'city' => 'Lima',
            'default_currency_code' => (string) DB::table('currencies')->value('code'),
            'timezone' => 'America/Lima',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    /**
     * Una campaña en el estado que se pida, con lo que ese estado exige.
     *
     * Un `in_progress` no es una palabra que se teclee: arrastra **dos**
     * restricciones que sólo se descubren estrellándose contra ellas.
     *
     * | Restricción | Qué exige fuera de borrador |
     * |---|---|
     * | `ck_camp_confirmed` | `confirmed_at` — el instante desde el que la campaña ya no se borra |
     * | `ck_camp_billing_entity` | `billing_legal_entity_id` — quién la factura (`BR-LE-001`, 7.1) |
     *
     * La segunda llegó con 7.1 y dejó obsoletos tres fixtures escritos a mano
     * en `PerfilComercialTest` el mismo día que se creó. Es exactamente el
     * síntoma que describía `T-13`: *cada restricción nueva los deja obsoletos
     * de uno en uno*. Por eso este método existe y por eso vive aquí.
     *
     * ### El nombre lleva `De` a propósito
     *
     * `campana()` a secas chocaba con el ayudante que `PerfilComercialTest` ya
     * tenía, y en PHP **el método de la clase gana al del trait, en silencio**:
     * la prueba seguía llamando al suyo y el error salía tres capas más abajo,
     * hablando de un argumento que nadie había escrito.
     *
     * @param array<string, mixed> $cambios
     */
    protected function campanaDe(int $clienteId, int $marcaId, array $cambios = []): int
    {
        $estado = (string) ($cambios['status'] ?? 'draft');
        $borrador = in_array($estado, ['draft', 'pending_approval', 'cancelled'], true);

        return (int) DB::table('campaigns')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'code' => 'CMP-'.mb_substr((string) Str::uuid(), 0, 8),
            'name' => 'Campaña de prueba',
            'client_organization_id' => $clienteId,
            'client_brand_id' => $marcaId,
            'currency_code' => (string) DB::table('currencies')->value('code'),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
            'status' => $estado,
            'confirmed_at' => $borrador ? null : now(),
            // Sin sociedad sólo puede quedarse un borrador. Se crea una si hace
            // falta en vez de fallar con un `3819` que nombra la restricción y
            // no lo que falta.
            'billing_legal_entity_id' => $borrador ? null : $this->entidadLegal(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private static function datosDeCreador(array $cambios): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'display_name' => 'anatorres',
            'birth_date' => '1998-05-12',
            'email' => 'ana@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE',
            'document_type' => 'DNI',
            'document_number' => '40000001',
            'status' => 'pending',
            'payment_term_days' => 30,
            'preferred_currency_code' => 'PEN',
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios);
    }
}
