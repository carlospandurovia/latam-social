<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Models\User;
use App\Shared\Auth\Permisos;
use App\Shared\Crypto\CuentaBancaria;
use App\Shared\Database\Vigencia;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Deja a un creador **operativamente completo**: `BR-CREATOR-006` entera.
     *
     * Es lo que 7.4 vino a necesitar: la lista corta veta a quien no cumple, así
     * que casi toda prueba que meta a alguien en una campaña necesita primero un
     * creador que pase las seis condiciones.
     *
     * ### Y no contradice lo que dice la cabecera de este archivo
     *
     * `creadorActivo()` sigue escribiendo lo que la **base** acepta como activo,
     * y `ActivacionCreadorTest` sigue recorriendo las pantallas de verdad. Esto
     * es otra cosa: escribe las **evidencias** que la regla exige, saltándose las
     * pantallas a propósito, porque quien lo usa no está probando la activación
     * — la está dando por hecha para probar otra cosa.
     *
     * Quien lo llame debe comprobar el resultado con `CompletitudOperativa` y
     * fallar en voz alta si no cumple. Un apoyo que se cree completo sin serlo
     * convierte «el veto no salta» en una prueba verde que no prueba nada, y ese
     * es exactamente el modo de fallo que este proyecto ya ha pagado tres veces.
     *
     * Sólo sirve para creadores **mayores de edad**: la tutela de un menor
     * arrastra perfil fiscal y cuenta del tutor, y montarlo aquí escondería la
     * mitad interesante de `BR-CREATOR-010`.
     */
    protected function completar(int $creadorId): void
    {
        $capturador = (int) $this->usuarioCon('campaign_manager')->id;
        $verificador = (int) $this->usuarioCon('finance')->id;
        $paisId = (int) DB::table('creators')->where('id', $creadorId)->value('country_id');

        DB::table('social_accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'creator_id' => $creadorId,
            'platform_id' => DB::table('platforms')->where('is_active', 1)->value('id'),
            'handle' => 'cuenta'.$creadorId,
            'profile_url' => 'https://ejemplo.test/cuenta'.$creadorId,
            'verification_status' => 'verified',
            'verification_method' => 'manual_review',
            'verified_by_user_id' => $verificador,
            'verified_at' => now()->subDay(),
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `valid_from` en el pasado: «vigente» exige que YA HAYA EMPEZADO
        // (`T-21`). Con la fecha de hoy la prueba pasaria, y con la de manana
        // fallaria por un motivo que nadie relacionaria con este apoyo.
        DB::table('creator_tax_profiles')->insert([
            'creator_id' => $creadorId,
            'holder_type' => 'creator',
            'country_id' => $paisId,
            'tax_regime_code' => 'RER',
            'tax_id_type' => 'RUC',
            'tax_id_number' => '10'.str_pad((string) (400000000 + $creadorId), 9, '0', STR_PAD_LEFT),
            'issued_document_type' => 'factura',
            'withholding_status' => 'not_applicable',
            'created_by_user_id' => $capturador,
            'status' => 'approved',
            // Capturador y aprobador DISTINTOS: `ck_ctp_segregation`, que es la
            // separacion de funciones del dinero (`BR-FIN-005`).
            'approved_by_user_id' => $verificador,
            'approved_at' => now()->subDay(),
            'valid_from' => now()->subMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `eligible_from` NO se pasa: lo pone `medioDePago()` a partir del mismo
        // instante que `verified_at`.
        //
        // Pasarlo aqui era un FALLO INTERMITENTE. `ck_cpm_eligible_after` exige
        // `eligible_from >= verified_at` --un enfriamiento negativo no
        // significa nada-- y los dos salian de dos llamadas distintas a
        // `now()->subDay()`, separadas por la creacion de un usuario. Cuando
        // esas dos llamadas caian a los dos lados de un segundo, la elegibilidad
        // quedaba un segundo ANTES de la verificacion y la base lo rechazaba.
        //
        // Fallaba una vez de muchas y siempre en una prueba distinta, que es la
        // peor forma de fallar: parece un problema de la prueba que toco.
        $this->medioDePago($creadorId, '19100000000'.$creadorId, [
            'status' => 'verified',
            'is_default' => 1,
        ]);

        $version = DB::table('terms_versions')
            ->where('audience', 'creator')->whereNull('effective_to')->value('id')
            ?? $this->publicarTerminos();

        // `ck_terms_acceptances_backing`: una aceptacion que NO llego por el
        // portal exige quien la registro Y el papel que lo respalda. Es la regla
        // que impide que alguien teclee «acepto» en nombre de otro sin dejar
        // rastro. `admin` ni siquiera es un canal valido --lo son portal, email,
        // whatsapp, paper y phone-- y el 3819 lo dijo antes de que nadie lo
        // teclease en produccion.
        DB::table('terms_acceptances')->insert([
            'uuid' => (string) Str::uuid(),
            'subject_type' => 'creator',
            'subject_id' => $creadorId,
            'terms_version_id' => $version,
            'accepted_at' => now(),
            'channel' => 'email',
            'recorded_by_user_id' => $capturador,
            'evidence_file_id' => $this->archivoDeIdentidad('aceptacion-'.$creadorId.'.pdf'),
            'created_at' => now(),
        ]);
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
            // 9.16: una version sembrada esta PUBLICADA. Sin `published_at` es
            // un borrador, y un borrador ni rige ni se puede cerrar
            // (`ck_terms_borrador_abierto`).
            'published_at' => now(),
            'published_by_user_id' => $this->usuarioCon('admin')->id,
            'review_status' => 'revisado',
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
     * | `ck_cpm_eligible` / `_after` | verificado exige `eligible_from`, y no antes de la verificación |
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

        // UN solo instante para todo el fixture. Cada `now()` suelto es una
        // oportunidad de cruzar un segundo entre dos columnas que la base
        // compara entre si.
        $ahora = now();
        $verificadoEn = $ahora->copy()->subDay();

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
            'verified_at' => $verificado ? $verificadoEn : null,
            'verified_by_user_id' => $verificado ? $quien() : null,
            // `ck_cpm_eligible` exige que un verificado diga desde cuando se le
            // puede pagar, y `ck_cpm_eligible_after` que no sea antes de
            // verificarlo. Se deriva del MISMO instante: quien quiera otra fecha
            // --un enfriamiento en curso, por ejemplo-- la pasa en `$cambios`.
            'eligible_from' => $verificado ? $verificadoEn : null,
            'closed_at' => $cerrado ? $ahora : null,
            'closed_by_user_id' => $cerrado ? $quien() : null,
            'is_default' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
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
     * | `ck_camp_revenue_declarado` | un ingreso **declarado**: o importe > 0, o `is_gratis` (7.2) |
     *
     * La segunda llegó con 7.1 y dejó obsoletos tres fixtures escritos a mano
     * en `PerfilComercialTest` el mismo día que se creó. Es exactamente el
     * síntoma que describía `T-13`: *cada restricción nueva los deja obsoletos
     * de uno en uno*. Por eso este método existe y por eso vive aquí.
     *
     * La tercera llegó con 7.2 **y ya no rompió ningún fixture escrito a mano**:
     * rompió este método, en un sitio, y se arregló en un sitio. Que la lista de
     * arriba crezca es lo esperado; lo que no vuelve a pasar es que crezca en
     * once ficheros a la vez.
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
            // Fuera de borrador el cero hay que explicarlo (`ck_camp_revenue_declarado`).
            // Se pone importe y no `is_gratis`: una campaña gratuita es el caso
            // raro, y un fixture por omisión tiene que parecerse al caso normal.
            'revenue_amount' => $borrador ? 0 : 1000,
            'is_gratis' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    /**
     * El formulario de alta de campaña, con todo lo que hoy exige.
     *
     * **Estaba copiado en TRES clases de prueba**, cada una con su propio
     * `datos()`. 7.5 anadio `creator_budget_amount` como obligatorio y las tres
     * se rompieron a la vez, con un «Attempt to read property id on null» que no
     * nombra el campo que falta — el sintoma clasico de `H-16`, y la cuarta vez
     * en este proyecto.
     *
     * Ahora vive aqui. La proxima columna obligatoria rompe **un** sitio.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    protected function datosDeCampana(int $clienteId, int $marcaId, array $cambios = []): array
    {
        return array_merge([
            'name' => 'Lanzamiento verano',
            'client_organization_id' => $clienteId,
            'client_brand_id' => $marcaId,
            'objective' => 'awareness',
            'currency_code' => (string) DB::table('currencies')->value('code'),
            'revenue_amount' => '15000.00',
            'is_gratis' => '0',
            'creator_budget_amount' => '5000.00',
            'included_revision_rounds' => 2,
            'min_creator_age' => 18,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ], $cambios);
    }

    /**
     * Un mercado para una campaña.
     *
     * Desde 7.3 una campaña necesita **al menos uno** para salir de borrador
     * (`BR-CAMPAIGN-004`), así que casi cualquier prueba que apruebe una campaña
     * pasa por aquí. Sin `$paisId` toma el primero del catálogo: qué país sea da
     * igual salvo que la prueba diga lo contrario, y elegirlo a mano en cada
     * sitio es la forma de que dejen de coincidir.
     */
    /** @param array<string, mixed> $cambios */
    protected function mercadoDe(int $campanaId, ?int $paisId = null, array $cambios = []): int
    {
        return (int) DB::table('campaign_markets')->insertGetId(array_merge([
            'campaign_id' => $campanaId,
            'country_id' => $paisId ?? (int) DB::table('countries')->orderBy('id')->value('id'),
            'target_creators' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    /**
     * Un requisito de formato para el brief de una campaña.
     *
     * Con esto una campaña «puede salir de borrador» (`BR-CAMPAIGN-004`, 7.2).
     * Por omisión es **general** —`campaign_market_id` a `null`, «todos los
     * mercados» (`N-03`)—; para uno de mercado se pasa en `$cambios`.
     * El formato se toma de los que ya hay sembrados en vez de crear uno: los
     * formatos son catálogo, y una prueba que se invente uno prueba contra un
     * catálogo que no existe en producción.
     *
     * @param array<string, mixed> $cambios
     */
    protected function requisitoDe(int $campanaId, array $cambios = []): int
    {
        return (int) DB::table('campaign_requirements')->insertGetId(array_merge([
            'campaign_id' => $campanaId,
            'campaign_market_id' => null,
            'content_format_id' => (int) DB::table('content_formats')->where('is_active', 1)->value('id'),
            'quantity' => 1,
            'deadline_offset_days' => 7,
            'permanence_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    /**
     * Una participación de un creador en una campaña (`T-104`).
     *
     * ### Por qué hacía falta
     *
     * `D-4` dejó dos indicadores —creadores participando y entregables
     * entregados— **verificados a medias**, porque fabricar esto a mano exige
     * creador activo, mercado, moneda, base de pacto y las cuatro reglas de
     * `ck_cc_*` a la vez. Cinco pasos de preparación en cada prueba acaban
     * comprobando la preparación.
     *
     * Lo que este ayudante sabe y no hay que recordar en cada sitio:
     *
     * - `ck_cc_accepted`: fuera de los estados previos, **exige** `accepted_at`.
     *   Es el instante que congela el acuerdo, no un adorno.
     * - `ck_cc_payee`: `creator` sin tutor, `guardian` con tutor. Media pareja
     *   no vale.
     * - `fk_ccr_market_campaign` es COMPUESTA: el mercado tiene que ser de esta
     *   campaña, no de otra. Por eso se busca el suyo en vez de tomar el primero.
     *
     * @param array<string, mixed> $cambios
     */
    protected function participacionDe(int $campanaId, ?int $creadorId = null, array $cambios = []): int
    {
        $creadorId ??= $this->creadorActivo();

        $mercadoId = DB::table('campaign_markets')->where('campaign_id', $campanaId)->value('id')
            ?? $this->mercadoDe($campanaId);

        $estado = (string) ($cambios['status'] ?? 'accepted');
        $previos = ['shortlisted', 'invited', 'declined', 'expired', 'cancelled'];

        return (int) DB::table('campaign_creators')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campanaId,
            'creator_id' => $creadorId,
            'campaign_market_id' => $mercadoId,
            'status' => $estado,
            'agreed_amount' => 500,
            'agreed_basis' => 'gross',
            'currency_code' => (string) DB::table('campaigns')->where('id', $campanaId)->value('currency_code'),
            'payee_type' => 'creator',
            'payment_term_days_snapshot' => 30,
            'invited_at' => now(),
            'accepted_at' => in_array($estado, $previos, true) ? null : now(),
            'declined_at' => $estado === 'declined' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios));
    }

    /**
     * Un entregable de una participación (`T-104`).
     *
     * Dos reglas del esquema que se olvidan siempre y aquí están resueltas:
     *
     * - `ck_del_due_futuro`: `due_on` no puede ser anterior a la fecha de
     *   creación. Para fabricar un entregable **vencido** hay que retrasar
     *   también `created_at`, no sólo la fecha límite. Sin esto, la prueba de
     *   «entregables vencidos» falla con un `4025` que no dice eso.
     * - `uq_del_sequence`: el trío participación + requisito + número es único,
     *   así que el segundo entregable de la misma pareja necesita otro número.
     *   Se calcula solo; a mano se olvida a la segunda llamada.
     *
     * @param array<string, mixed> $cambios
     */
    protected function entregableDe(int $participacionId, array $cambios = []): int
    {
        $campanaId = (int) DB::table('campaign_creators')
            ->where('id', $participacionId)->value('campaign_id');

        $requisitoId = $cambios['campaign_requirement_id']
            ?? DB::table('campaign_requirements')->where('campaign_id', $campanaId)->value('id')
            ?? $this->requisitoDe($campanaId);

        $estado = (string) ($cambios['status'] ?? 'pending');
        $entregados = ['submitted', 'in_review', 'changes_requested', 'approved', 'published', 'verified', 'removed'];

        $creado = $cambios['created_at'] ?? now()->toDateTimeString();
        $siguiente = 1 + (int) DB::table('deliverables')
            ->where('campaign_creator_id', $participacionId)
            ->where('campaign_requirement_id', $requisitoId)
            ->max('sequence_number');

        return (int) DB::table('deliverables')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'campaign_creator_id' => $participacionId,
            'campaign_requirement_id' => $requisitoId,
            'sequence_number' => $siguiente,
            'status' => $estado,
            'revision_rounds_used' => 0,
            'due_on' => CarbonImmutable::parse((string) $creado)->addDays(7)->toDateString(),
            'submitted_at' => in_array($estado, $entregados, true) ? $creado : null,
            'created_at' => $creado,
            'updated_at' => $creado,
        ], $cambios));
    }

    /**
     * Una factura EMITIDA, con su número salido del libro (`T-115`).
     *
     * ### Por qué hacía falta
     *
     * `D-8` puso los números del dinero en el panel y los dejó **sin probar**,
     * porque fabricar una factura emitida es la cadena entera del correlativo:
     * sociedad emisora, perfil fiscal del cliente, tipo de comprobante del país,
     * serie, número reservado en el libro (`ck_invoice_numerada`) y los seis
     * campos congelados. Seis pasos antes de poder afirmar una suma.
     *
     * ### Las tres reglas que se olvidan siempre, resueltas aquí
     *
     * - **`ck_invoice_numerada`**: emitida exige serie, número **y**
     *   `document_number_id`. No basta con poner un número a mano: tiene que
     *   salir del libro, que es lo que permite cruzar el comprobante con él.
     * - **`tg_invoice_tipo_ins`**: si hay país congelado, el `document_type`
     *   tiene que existir en el catálogo **de ese país**. Se toma uno real de
     *   `document_types` en vez de escribir `'invoice'` a mano, que es
     *   exactamente el enum que `9.12` vino a quitar (`DEC-228`).
     * - **`ck_invoice_math`** y el veto de `gravado` con impuesto cero: el total
     *   es subtotal + impuesto, y el impuesto no puede ser 0 en régimen gravado.
     *
     * Los campos congelados posteriores a `9.9b` se rellenan **sólo si la
     * columna existe**: así una migración que añada otro snapshot no rompe cada
     * prueba de finanzas el día que entre.
     *
     * @param array<string, mixed> $cambios
     */
    protected function facturaEmitida(int $clienteId, array $cambios = []): int
    {
        $sociedadId = $this->entidadLegal();
        $paisId = (int) DB::table('client_organizations')->where('id', $clienteId)->value('country_id');

        $perfilId = DB::table('client_tax_profiles')
            ->where('client_organization_id', $clienteId)->value('id')
            ?? DB::table('client_tax_profiles')->insertGetId([
                'client_organization_id' => $clienteId,
                'country_id' => $paisId,
                'legal_name' => 'Cliente de prueba S.A.C.',
                'tax_id_type' => 'RUC',
                'tax_id_number' => '20'.mb_substr((string) time(), -9),
                // `address_line1` es NOT NULL y sin valor por defecto: la
                // direccion fiscal sale IMPRESA en el comprobante, asi que el
                // esquema no admite una factura sin ella.
                'address_line1' => 'Av. del cliente 456',
                'city' => 'Lima',
                'payment_term_days' => 30,
                'valid_from' => '2020-01-01',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        // El tipo sale del catalogo del pais, nunca escrito a mano. Se prefiere
        // uno cuya serie empiece por `F` --factura-- porque es la unica forma
        // que este ayudante sabe fabricar; si no lo hubiera, el disparador
        // `tg_ds_forma_ins` protestaria con su propio mensaje, que se lee mejor
        // que un fallo de este archivo.
        $tipo = DB::table('document_types')->where('country_id', $paisId)
            ->where('is_active', 1)->where('series_pattern', 'like', '^F%')
            ->orderBy('sort_order')->first()
            ?? DB::table('document_types')->where('country_id', $paisId)
                ->where('is_active', 1)->orderBy('sort_order')->first();

        if ($tipo === null) {
            $this->fail('No hay tipos de comprobante sembrados para ese país. ¿Falta `CimientosSeeder`?');
        }

        // `^F[A-Z0-9]{3}$`: MAYUSCULAS. Un uuid da hexadecimal en minusculas y
        // el disparador lo rechaza --costo una vuelta averiguarlo--.
        $serie = 'F'.mb_strtoupper(mb_substr(md5((string) Str::uuid()), 0, 3));

        $serieId = DB::table('document_series')->insertGetId([
            'legal_entity_id' => $sociedadId,
            'document_type_id' => $tipo->id,
            'series' => $serie,
            'next_number' => 2,
            // `ck_ds_env` solo admite `sandbox` y `production`. No hay «test»:
            // una serie de pruebas ES una serie de sandbox ante la
            // administracion, y el esquema no deja inventarse un tercer mundo.
            'environment' => 'sandbox',
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $numero = 1 + (int) DB::table('document_numbers')->where('document_series_id', $serieId)->max('number');

        // Un BORRADOR no gasta numero (`ck_invoice_borrador_sin_numero`), asi
        // que tampoco se reserva: reservarlo dejaria un correlativo colgado que
        // la pantalla de series marcaria en rojo, con toda la razon.
        $esBorrador = ($cambios['status'] ?? 'issued') === 'draft';

        $numeroId = $esBorrador ? null : DB::table('document_numbers')->insertGetId([
            'document_series_id' => $serieId,
            'number' => $numero,
            // Los digitos del correlativo los declara el TIPO, no este archivo.
            'full_number' => $serie.'-'.str_pad(
                (string) $numero, (int) ($tipo->number_length ?? 8), '0', STR_PAD_LEFT,
            ),
            // Nace RESERVADO, no usado: `ck_dn_usado` exige `entity_id` y la
            // factura todavia no existe. Es el orden real --reservar, emitir,
            // marcar usado-- y el fixture lo respeta en vez de saltarselo:
            // saltarselo seria fabricar un estado que la aplicacion no produce.
            'status' => 'reserved',
            'reserved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $emision = $cambios['issue_date'] ?? now()->toDateString();

        $datos = array_merge([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $sociedadId,
            'client_organization_id' => $clienteId,
            'client_tax_profile_id' => $perfilId,
            'document_type' => (string) $tipo->code,
            'series' => $esBorrador ? null : $serie,
            'number' => $esBorrador ? null : $numero,
            'document_number_id' => $numeroId,
            'issue_date' => $emision,
            'due_date' => CarbonImmutable::parse((string) $emision)->addDays(30)->toDateString(),
            'currency_code' => 'PEN',
            'tax_regime' => 'gravado',
            // 100 + 18 = 118. `ck_invoice_math`, y el impuesto NO puede ser cero
            // en regimen gravado: es lo que `9.9a` existe para impedir.
            'subtotal_amount' => 100,
            'tax_amount' => 18,
            'total_amount' => 118,
            'status' => 'issued',
            // `ck_invoice_issued`: fuera de borrador hay que decir CUANDO se
            // emitio. Sin fecha de emision un comprobante no existe ante la
            // administracion, aunque tenga numero.
            'issued_at' => now(),
            // `ck_invoice_gravado_con_tasa`: una factura gravada y emitida
            // lleva la tasa CONGELADA. `DEC-251`: preguntarla otra vez dentro
            // de tres años obliga a confiar en que nadie toco la vigencia, y la
            // copia no confia en nadie.
            'tax_rate_snapshot' => 18,
            'issuer_legal_name_snapshot' => 'Emisora de prueba S.A.C.',
            'issuer_tax_id_snapshot' => '20603203896',
            'issuer_address_snapshot' => 'Av. de prueba 123, Lima',
            'receiver_legal_name_snapshot' => 'Cliente de prueba S.A.C.',
            'receiver_tax_id_snapshot' => '20123456789',
            'receiver_address_snapshot' => 'Av. del cliente 456, Lima',
            'created_at' => now(), 'updated_at' => now(),
        ], $cambios);

        // Solo lo que el esquema de HOY tiene. El pais congelado es el que
        // enciende `tg_invoice_tipo_ins`, asi que se pone de verdad.
        foreach ([
            'issuer_country_snapshot' => 'PE',
            'receiver_country_snapshot' => 'PE',
        ] as $columna => $valor) {
            if (Schema::hasColumn('invoices', $columna) && !array_key_exists($columna, $cambios)) {
                $datos[$columna] = $valor;
            }
        }

        // **Nace borrador aunque vaya a acabar emitida.** `tg_iline_solo_borrador`
        // no deja anadir lineas a una factura que ya salio --anadirlas cambiaria
        // lo que dice el documento sin tocar el documento-- asi que el fixture
        // no puede fabricar el estado final de un tirazo: tiene que recorrer el
        // camino que recorre la aplicacion. Escribir el estado final directo era
        // justo la clase de atajo que produce un estado que el sistema no sabe
        // producir (`T-116`).
        $facturaId = (int) DB::table('invoices')->insertGetId(array_merge($datos, [
            'status' => 'draft',
            'series' => null,
            'number' => null,
            'document_number_id' => null,
            'issued_at' => null,
        ]));

        DB::table('invoice_lines')->insert([
            'invoice_id' => $facturaId,
            'line_number' => 1,
            'description' => 'Servicio de campaña',
            'quantity' => 1,
            'unit_price' => $datos['subtotal_amount'],
            'line_subtotal' => $datos['subtotal_amount'],
            'tax_rate' => 18,
            'line_tax' => $datos['tax_amount'],
            'line_total' => $datos['total_amount'],
        ]);

        if ($esBorrador) {
            return $facturaId;
        }

        // La emision de verdad: `tg_invoice_emision` comprueba aqui que la
        // factura tiene lineas y que las lineas SUMAN lo que dice la cabecera.
        // Que este fixture pase por ese disparador es la mitad de su valor.
        DB::table('invoices')->where('id', $facturaId)->update([
            'status' => $datos['status'],
            'series' => $datos['series'],
            'number' => $datos['number'],
            'document_number_id' => $datos['document_number_id'],
            'issued_at' => $datos['issued_at'],
            'updated_at' => now(),
        ]);

        // Y ahora si: el numero pasa a usado y dice A QUE documento fue. Sin
        // esto, `document_numbers` no podria cruzarse con el libro, que es para
        // lo que existe (`DEC-230`).
        DB::table('document_numbers')->where('id', $numeroId)->update([
            'status' => 'used',
            'used_at' => now(),
            'entity_type' => 'invoice',
            'entity_id' => $facturaId,
            'updated_at' => now(),
        ]);

        return $facturaId;
    }

    /**
     * Un cobro contra una factura.
     *
     * @param array<string, mixed> $cambios
     */
    protected function cobroDe(int $facturaId, float $importe, array $cambios = []): int
    {
        $factura = DB::table('invoices')->where('id', $facturaId)->first();

        return (int) DB::table('payments')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'invoice_id' => $facturaId,
            'amount' => $importe,
            // La moneda del cobro es la de SU factura: un cobro en otra moneda
            // es una conversion, y eso no lo decide un fixture.
            'currency_code' => (string) ($factura->currency_code ?? 'PEN'),
            'method' => 'transfer',
            'received_on' => now()->toDateString(),
            'created_at' => now(),
        ], $cambios));
    }

    /**
     * Una publicación de un entregable, en el estado que se pida (`D-14`).
     *
     * Escribe la fila directamente en vez de recorrer entregar → aprobar →
     * reportar → verificar, que es lo que hace `PermanenciaTest`: para probar el
     * ciclo de vida hay que recorrerlo, pero para CONTAR publicaciones por fecha
     * el camino largo sólo añade minutos y acoplamiento.
     *
     * Las reglas que el esquema exige y que se olvidan siempre, resueltas aquí:
     *
     * - `ck_pub_published_no_futuro`: `published_at` no puede ser posterior a
     *   `created_at`. Para fabricar una publicación vieja hay que retrasar las
     *   **dos**.
     * - `ck_pub_verified`: verificada exige verificador Y fecha.
     * - `ck_pub_removed`: caída exige cuándo, **quién lo firma** y un motivo de
     *   al menos cinco caracteres.
     * - `ck_pub_fulfilled`: cumplida exige `fulfilled_at` y `permanence_until`.
     * - `ck_pub_permanence`: `permanence_until` sólo existe fuera de `reported`.
     * - `uq_pub_fingerprint`: única entre las vivas, así que la huella varía en
     *   cada llamada. `viva_gate` es una columna **generada**: no se escribe.
     * - `tg_pub_version_aprobada`: **sólo se publica lo aprobado, y la versión
     *   aprobada**. La publicación apunta a una versión concreta y esa versión
     *   tiene que ser la que el entregable tiene aprobada, así que el ayudante
     *   fabrica la cadena entera --versión, aprobación, puntero-- en el orden
     *   real. Y el orden importa: `tg_dv_entregable_abierto` no deja crear una
     *   versión de un entregable YA aprobado, así que primero la versión y
     *   después la aprobación, nunca al revés.
     *
     * @param array<string, mixed> $cambios
     */
    protected function publicacionDe(int $entregableId, array $cambios = []): int
    {
        $estado = (string) ($cambios['status'] ?? 'verified');
        $cuando = $cambios['published_at'] ?? now()->subDays(3);
        $verificador = $cambios['verified_by_user_id']
            ?? (int) $this->usuarioCon('content_reviewer')->id;

        $fila = [
            'uuid' => (string) Str::uuid(),
            'deliverable_id' => $entregableId,
            'deliverable_version_id' => $this->versionAprobadaDe($entregableId),
            'platform_id' => (int) DB::table('platforms')->orderBy('id')->value('id'),
            'url' => 'https://instagram.com/p/'.mb_substr((string) Str::uuid(), 0, 11),
            'url_fingerprint' => hash('sha256', (string) Str::uuid()),
            'published_at' => $cuando,
            'status' => $estado,
            // `created_at` NO puede ser anterior a `published_at`.
            'created_at' => $cuando,
            'updated_at' => now(),
        ];

        if (in_array($estado, ['verified', 'removed', 'fulfilled'], true)) {
            $fila['verified_at'] = $cambios['verified_at'] ?? $cuando;
            $fila['verified_by_user_id'] = $verificador;
            $fila['permanence_until'] = $cambios['permanence_until']
                ?? CarbonImmutable::parse((string) $cuando)->addDays(30)->toDateString();
        }

        if ($estado === 'removed') {
            $fila['removed_at'] = $cambios['removed_at'] ?? now();
            $fila['removed_by_user_id'] = $verificador;
            $fila['removed_reason'] = $cambios['removed_reason'] ?? 'El post ya no está en la cuenta';
        }

        if ($estado === 'fulfilled') {
            $fila['fulfilled_at'] = $cambios['fulfilled_at'] ?? now();
        }

        return (int) DB::table('publications')->insertGetId(array_merge($fila, $cambios));
    }

    /**
     * La versión aprobada de un entregable, fabricándola si hace falta.
     *
     * `tg_pub_version_aprobada` exige que el entregable esté aprobado Y que su
     * puntero señale a la versión que se publica. `ck_del_approved_version`
     * exige que aprobación y puntero vayan juntos, `ck_del_approved` y
     * `ck_del_submitted` que haya entrega antes que aprobación, y
     * `ck_del_aprobador` que se diga **quién** aprobó. Las cinco reglas dicen lo
     * mismo desde cinco sitios: **no se publica lo que nadie aprobó**, y
     * «nadie» incluye a un aprobador sin nombre.
     */
    private function versionAprobadaDe(int $entregableId): int
    {
        $entregable = DB::table('deliverables')->where('id', $entregableId)->first();

        if ($entregable === null) {
            throw new \RuntimeException("No existe el entregable {$entregableId}.");
        }

        if ($entregable->approved_version_id !== null) {
            return (int) $entregable->approved_version_id;
        }

        // La version PRIMERO: con el entregable ya aprobado,
        // `tg_dv_entregable_abierto` la rechazaria.
        $versionId = (int) DB::table('deliverable_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'deliverable_id' => $entregableId,
            'version_number' => 1 + (int) DB::table('deliverable_versions')
                ->where('deliverable_id', $entregableId)->max('version_number'),
            // `ck_dv_content` pide archivo o enlace, y `ck_dv_url_https` que sea
            // https: un entregable sin nada que mirar no es un entregable.
            'external_url' => 'https://entregas.example/'.mb_substr((string) Str::uuid(), 0, 8),
            'submitted_at' => $entregable->submitted_at ?? now(),
        ]);

        DB::table('deliverables')->where('id', $entregableId)->update([
            'status' => 'approved',
            // `ck_del_submitted`: aprobado exige CUANDO se entrego.
            'submitted_at' => $entregable->submitted_at ?? now(),
            'approved_at' => now(),
            // `ck_del_aprobador`: y QUIEN lo aprobo. Un entregable aprobado por
            // nadie es el que no se puede defender cuando el cliente pregunta.
            'approved_by_user_id' => (int) $this->usuarioCon('content_reviewer')->id,
            'approved_version_id' => $versionId,
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    // ------------------------------------------------------------------ apoyo

    /** Cuantos creadores lleva creados esta prueba, para no repetir documento ni correo. */
    private static int $creadoresCreados = 0;

    /**
     * Los datos por omision de un creador.
     *
     * `document_number` y `email` **varian en cada llamada**. `uq_creators_email`
     * y `uq_creators_identity` son unicos entre los creadores no anonimizados, y
     * con valores fijos la segunda llamada daba un `1062` que hablaba de un
     * indice --no de que la prueba necesitaba DOS creadores--. Salio en 7.4, que
     * es la primera iteracion que crea varios a la vez.
     *
     * `display_name` tambien cambia, aunque no sea unico: dos «anatorres» en una
     * lista de candidatos hacen ilegible cualquier fallo.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private static function datosDeCreador(array $cambios): array
    {
        $n = ++self::$creadoresCreados;

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'display_name' => 'creador'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'birth_date' => '1998-05-12',
            'email' => 'creador'.$n.'@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE',
            'document_type' => 'DNI',
            'document_number' => str_pad((string) (40000000 + $n), 8, '0', STR_PAD_LEFT),
            'status' => 'pending',
            'payment_term_days' => 30,
            'preferred_currency_code' => 'PEN',
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios);
    }
}
