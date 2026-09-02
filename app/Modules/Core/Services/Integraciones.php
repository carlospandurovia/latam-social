<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Config\Instalacion;
use App\Shared\Integracion\EntornoAjeno;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Las credenciales de cada API, en un solo sitio (9.17d).
 *
 * ### Dos métodos para el secreto, y son distintos a propósito
 *
 * `estado()` es lo que ve una persona —de dónde sale, sus cuatro últimos, quién
 * la puso y cuándo—. `secreto()` es lo que usa un cliente HTTP. Nunca se mezclan,
 * por el mismo motivo que en `CredencialFuente` desde `9.2`: el día que alguien
 * quiera enseñar «la configuración» en una vista, el método que tiene a mano es
 * el que no filtra nada.
 *
 * ### Rotar no es sobrescribir
 *
 * Guardar una credencial nueva **revoca la anterior y crea una versión nueva**
 * (`docs/12` §3.2). `tg_icred_inmutable` lo impone: una fila de credencial no se
 * reescribe. Así se puede volver atrás si la nueva es incorrecta y se puede
 * contestar «¿cuándo cambió y quién la puso?», que es la mitad del motivo de que
 * esta tabla exista.
 *
 * ### Y nada de esto entra en la bitácora con su valor
 *
 * `BR-SEC-001` y la regla de no guardar información sensible innecesariamente en
 * los registros. Se anota **que** se cambió una credencial, de qué conexión y de
 * qué clase; nunca el valor ni los cuatro últimos.
 */
final class Integraciones
{
    /** @var array<string, string> */
    public const ENTORNOS = ['sandbox' => 'Pruebas', 'production' => 'Producción'];

    /** @var array<string, string> */
    public const ESTADOS = [
        'draft' => 'Borrador — todavía no se usa',
        'active' => 'Activa — es la que se usa',
        'disabled' => 'Desactivada',
    ];

    /**
     * Los propósitos, en palabras.
     *
     * Sólo para los mensajes: «no hay conexión activa de invoicing» le habla al
     * que escribió el esquema, no al que tiene que arreglarlo.
     *
     * @var array<string, string>
     */
    public const PROPOSITOS = [
        'invoicing' => 'facturación electrónica',
        'fx' => 'tipos de cambio',
        'email' => 'correo',
        'payment' => 'pagos',
        'identity' => 'identidad',
        'other' => 'otros',
    ];

    /** @var array<string, string> */
    public const CLASES = [
        'api_key' => 'Clave de API',
        'password' => 'Contraseña',
        'token' => 'Token',
        'webhook_secret' => 'Secreto de webhook',
        'client_secret' => 'Secreto de cliente',
    ];

    /**
     * La conexión que hay que usar para un propósito, o el motivo de que no.
     *
     * **Ésta es la puerta.** Antes de `9.22a` cada consumidor se armaba su
     * propia consulta a `integration_connections`, y eso significaba que la
     * barrera de entorno de `DEC-029` habría que acordarse de escribirla en cada
     * uno —que es como se escriben las barreras que un día faltan justo donde
     * hacía falta—. Aquí se pregunta una vez.
     *
     * El orden importa y no es casual: **primero se comprueba el entorno y
     * después se busca**. Al revés, el secreto ya estaría descifrado en memoria
     * antes de saber si esta máquina tenía derecho a mirarlo.
     *
     * @throws EntornoAjeno Si esta instalación no puede hablar con ese entorno.
     * @throws RuntimeException Si no hay ninguna conexión activa que sirva.
     */
    public static function conexionParaUsar(string $proposito, ?int $sociedadId, string $entorno): object
    {
        if (($motivo = Instalacion::porQueNoPuedeUsar($entorno)) !== null) {
            throw new EntornoAjeno($motivo);
        }

        Instalacion::anotarAnulacion($proposito, $entorno);

        $consulta = DB::table('integration_connections as ic')
            ->join('integration_providers as ip', 'ip.id', '=', 'ic.integration_provider_id')
            ->where('ip.purpose', $proposito)
            ->where('ic.status', 'active')
            ->where('ic.environment', $entorno);

        if ($sociedadId !== null) {
            $consulta->where('ic.legal_entity_id', $sociedadId);
        }

        $conexion = $consulta->first(['ic.id', 'ic.uuid', 'ic.name', 'ic.username', 'ic.environment']);

        if ($conexion === null) {
            throw new RuntimeException(sprintf(
                'No hay ninguna conexión activa de «%s» en el entorno «%s»%s. Se configura en Integraciones.',
                self::PROPOSITOS[$proposito] ?? $proposito,
                self::ENTORNOS[$entorno] ?? $entorno,
                $sociedadId === null ? '' : ' para esa sociedad',
            ));
        }

        return $conexion;
    }

    /**
     * Las conexiones, con su proveedor y su sociedad.
     *
     * @return Collection<int, \stdClass>
     */
    public static function conexiones(): Collection
    {
        return DB::table('integration_connections as ic')
            ->join('integration_providers as ip', 'ip.id', '=', 'ic.integration_provider_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'ic.legal_entity_id')
            // 9.17e: el extremo que el proveedor declara para ese entorno. La
            // pantalla ensena cual se USA y si es propia o heredada, porque
            // «no se ve la URL» y «la URL esta mal» se arreglan distinto.
            ->leftJoin('integration_provider_endpoints as pe', function ($union): void {
                $union->on('pe.integration_provider_id', '=', 'ic.integration_provider_id')
                    ->on('pe.environment', '=', 'ic.environment');
            })
            ->orderBy('ip.purpose')->orderBy('ip.name')->orderBy('ic.environment')
            ->get(['ic.id', 'ic.uuid', 'ic.name', 'ic.environment', 'ic.base_url',
                'ic.username', 'ic.status', 'ic.last_verified_at', 'ic.last_success_at',
                'ic.last_error_at', 'ic.last_error_message',
                'ip.code as proveedor', 'ip.name as proveedor_nombre', 'ip.purpose',
                'le.code as sociedad',
                'pe.base_url as url_del_proveedor', 'pe.label as etiqueta_del_proveedor']);
    }

    /** @return Collection<int, \stdClass> */
    public static function proveedores(): Collection
    {
        return DB::table('integration_providers')->where('is_active', 1)
            ->orderBy('purpose')->orderBy('name')->get(['id', 'code', 'name', 'purpose']);
    }

    /**
     * A dónde llama de verdad una conexión.
     *
     * La suya si la tiene; si no, la que el proveedor declara para ese entorno.
     * **Es la única forma correcta de preguntarlo**: leer `base_url` a secas
     * devuelve `null` en el caso normal —el que hereda— y quien llame se irá a
     * ninguna parte sin saber por qué.
     */
    public static function urlDe(int $conexionId): ?string
    {
        $fila = DB::table('integration_connections as ic')
            ->leftJoin('integration_provider_endpoints as pe', function ($union): void {
                $union->on('pe.integration_provider_id', '=', 'ic.integration_provider_id')
                    ->on('pe.environment', '=', 'ic.environment');
            })
            ->where('ic.id', $conexionId)
            ->first(['ic.base_url', 'pe.base_url as heredada']);

        if ($fila === null) {
            return null;
        }

        $propia = (string) ($fila->base_url ?? '');

        return $propia !== '' ? $propia : (($fila->heredada ?? null) === null ? null : (string) $fila->heredada);
    }

    /**
     * Los extremos que declara cada proveedor, para enseñarlos en el formulario.
     *
     * @return Collection<int, \stdClass>
     */
    public static function extremos(): Collection
    {
        if (!Schema::hasTable('integration_provider_endpoints')) {
            return collect();
        }

        return DB::table('integration_provider_endpoints as pe')
            ->join('integration_providers as ip', 'ip.id', '=', 'pe.integration_provider_id')
            ->orderBy('ip.name')->orderBy('pe.environment')
            ->get(['pe.integration_provider_id', 'pe.environment', 'pe.base_url', 'pe.label',
                'pe.notes', 'ip.name as proveedor']);
    }

    public static function porUuid(string $uuid): object
    {
        $fila = DB::table('integration_connections')->where('uuid', $uuid)->first();

        if ($fila === null) {
            throw new RuntimeException('No existe esa conexion.');
        }

        return $fila;
    }

    /**
     * Lo que se le puede enseñar a una persona. **Nunca el secreto.**
     *
     * @return list<array{clase: string, ultimos: ?string, version: int, puesta_por: ?string, puesta_el: string}>
     */
    public static function estado(int $conexionId): array
    {
        return DB::table('integration_credentials as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.set_by_user_id')
            ->where('c.integration_connection_id', $conexionId)
            ->whereNull('c.revoked_at')
            ->orderBy('c.kind')
            ->get(['c.kind', 'c.last4', 'c.version', 'c.set_at', 'u.name as autor'])
            ->map(fn (object $f): array => [
                'clase' => (string) $f->kind,
                'ultimos' => $f->last4 === null ? null : (string) $f->last4,
                'version' => (int) $f->version,
                'puesta_por' => $f->autor === null ? null : (string) $f->autor,
                'puesta_el' => (string) $f->set_at,
            ])
            ->all();
    }

    /**
     * El secreto en claro, o `null` si no hay ninguno vivo de esa clase.
     *
     * Devuelve `null` y no lanza: «no hay credencial» es un estado normal —una
     * conexión recién creada está así— y quien llama tiene que poder decirlo con
     * palabras en vez de estrellarse.
     */
    /**
     * Revoca la credencial viva de una conexión. **No la borra: la marca.**
     *
     * Borrarla se lleva por delante quién la puso y hasta cuándo estuvo en uso,
     * y ésa es la primera pregunta el día que aparezca un consumo raro contra
     * el servicio. La fila se queda con su motivo y libera `uq_icred_vigente`,
     * así que se puede poner otra.
     */
    public static function revocarSecreto(
        int $conexionId,
        string $clase,
        string $motivo = 'Retirada desde el admin.',
    ): void {
        DB::table('integration_credentials')
            ->where('integration_connection_id', $conexionId)
            ->where('kind', $clase)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => mb_substr($motivo, 0, 255),
                'updated_at' => now(),
            ]);
    }

    public static function secreto(int $conexionId, string $clase): ?string
    {
        $cifrado = DB::table('integration_credentials')
            ->where('integration_connection_id', $conexionId)
            ->where('kind', $clase)
            ->whereNull('revoked_at')
            ->value('secret_cipher');

        return $cifrado === null ? null : Crypt::decryptString((string) $cifrado);
    }

    /**
     * Guarda un secreto: revoca el anterior y crea la versión siguiente.
     *
     * Las dos escrituras van en la misma transacción porque `uq_icred_vigente`
     * sólo admite una viva: al revés —crear y luego revocar— la base rechaza la
     * primera de las dos, y el orden importa igual que en la cobertura de `4.5`.
     */
    public static function guardarSecreto(
        int $conexionId,
        string $clase,
        string $secreto,
        int $usuarioId,
        string $motivo = 'Rotacion desde el admin.',
    ): void {
        $secreto = trim($secreto);

        if ($secreto === '') {
            throw new RuntimeException('Una credencial vacia no es una credencial.');
        }

        if (!array_key_exists($clase, self::CLASES)) {
            throw new RuntimeException('Clase de credencial no valida.');
        }

        DB::transaction(function () use ($conexionId, $clase, $secreto, $usuarioId, $motivo): void {
            $anterior = DB::table('integration_credentials')
                ->where('integration_connection_id', $conexionId)
                ->where('kind', $clase)
                ->whereNull('revoked_at')
                ->first(['id', 'version']);

            if ($anterior !== null) {
                DB::table('integration_credentials')->where('id', $anterior->id)->update([
                    'revoked_at' => now(),
                    'revoked_reason' => mb_substr($motivo, 0, 255),
                    'updated_at' => now(),
                ]);
            }

            DB::table('integration_credentials')->insert([
                'integration_connection_id' => $conexionId,
                'kind' => $clase,
                'secret_cipher' => Crypt::encryptString($secreto),
                'last4' => mb_substr($secreto, -4),
                'version' => (int) ($anterior->version ?? 0) + 1,
                'set_by_user_id' => $usuarioId,
                'set_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Ni el valor ni los cuatro ultimos: `BR-SEC-001`. Se anota QUE cambio.
        Bitacora::registrar(
            accion: 'integration.credential_set',
            tipoEntidad: 'integration_connection',
            idEntidad: $conexionId,
            cambios: ['clase' => ['antes' => null, 'despues' => $clase]],
        );
    }

    /**
     * Crea o actualiza una conexión.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarConexion(?string $uuid, array $datos, int $usuarioId): string
    {
        $campos = [
            'integration_provider_id' => (int) $datos['integration_provider_id'],
            'legal_entity_id' => ($datos['legal_entity_id'] ?? null) ?: null,
            'name' => (string) $datos['name'],
            'environment' => (string) $datos['environment'],
            'base_url' => ($datos['base_url'] ?? '') !== '' ? (string) $datos['base_url'] : null,
            'username' => ($datos['username'] ?? '') !== '' ? (string) $datos['username'] : null,
            'status' => (string) $datos['status'],
            'updated_at' => now(),
        ];

        if ($uuid === null) {
            $uuid = (string) Str::uuid();
            DB::table('integration_connections')->insert(
                $campos + ['uuid' => $uuid, 'created_at' => now()],
            );
        } else {
            $anterior = self::porUuid($uuid);
            DB::table('integration_connections')->where('id', $anterior->id)->update($campos);
        }

        Bitacora::registrar(
            accion: 'integration.connection_saved',
            tipoEntidad: 'integration_connection',
            idEntidad: (int) DB::table('integration_connections')->where('uuid', $uuid)->value('id'),
            cambios: ['estado' => ['antes' => null, 'despues' => $campos['status']]],
        );

        return $uuid;
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        if (!Schema::hasTable('integration_connections')) {
            return [];
        }

        $avisos = [];

        // Una conexion ACTIVA sin credencial es la que mas duele: parece
        // configurada, y la primera llamada de verdad sale sin clave.
        $sinCredencial = DB::table('integration_connections as ic')
            ->join('integration_providers as ip', 'ip.id', '=', 'ic.integration_provider_id')
            ->where('ic.status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('integration_credentials as c')
                ->whereColumn('c.integration_connection_id', 'ic.id')
                ->whereNull('c.revoked_at'))
            ->pluck('ic.name');

        if ($sinCredencial->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Sin credencial: %s. La conexión está activa, así que parece configurada, y la '
                .'primera llamada de verdad saldrá sin clave.',
                $sinCredencial->implode(', '),
            ));
        }

        // Lo que se quedo a medias. No es rojo: un borrador es un borrador.
        $borradores = DB::table('integration_connections')->where('status', 'draft')->count();

        if ($borradores > 0) {
            $avisos[] = Aviso::ambar(sprintf(
                '%d %s en borrador: no se usan hasta que se activen.',
                $borradores, $borradores === 1 ? 'conexión' : 'conexiones',
            ));
        }

        // Un error reciente que nadie ha mirado. Se compara con el ultimo exito
        // porque un error de hace un mes seguido de mil llamadas buenas no es
        // un aviso: es historia.
        $conError = DB::table('integration_connections')
            ->where('status', 'active')
            ->whereNotNull('last_error_at')
            ->where(fn ($q) => $q->whereNull('last_success_at')
                ->orWhereColumn('last_error_at', '>', 'last_success_at'))
            ->pluck('name');

        if ($conError->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'El último intento falló y todavía no ha habido uno bueno después: %s.',
                $conError->implode(', '),
            ));
        }

        return $avisos;
    }
}
