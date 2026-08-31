<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Database\Vigencia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Los términos, editables desde el admin (9.16).
 *
 * ### Lo que cambia respecto de 3.5
 *
 * Publicar términos era un comando de consola, y sin términos publicados no se
 * activa ningún creador. Eso convertía una **configuración** en un bloqueo. El
 * sistema arranca ahora con un texto base sembrado y todo se cambia desde la
 * pantalla; el comando sigue existiendo para el despliegue.
 *
 * ### Borrador, publicada, cerrada
 *
 * Un borrador (`published_at IS NULL`) se edita libremente. Al publicarlo se
 * congela —`tg_terms_inmutable`— y cierra la versión anterior el día antes, así
 * que nunca hay dos vigentes el mismo día.
 *
 * ### El cambio menor
 *
 * Al publicar hay que declarar si el cambio es **de fondo** o **menor**. De
 * fondo: todos vuelven a aceptar. Menor: la aceptación anterior sigue valiendo.
 *
 * `versionesQueValen()` es donde eso se hace verdad: recorre hacia atrás la
 * cadena de versiones mientras cada una se declaró menor, y devuelve todas las
 * que cuentan. Un `fondo` corta la cadena.
 *
 * **Lo decide una persona y queda escrito.** Nadie puede deducir después que
 * aquello fue menor: la fila dice quién lo declaró y cuándo.
 */
final class Terminos
{
    /** @var array<string, string> */
    public const REVISION = [
        'sin_revisar' => 'Sin revisión legal',
        'en_revision' => 'En revisión legal',
        'revisado' => 'Revisado legalmente',
    ];

    /** @var array<string, string> */
    public const CAMBIO = [
        'fondo' => 'De fondo — todos vuelven a aceptar',
        'menor' => 'Menor — la aceptación anterior sigue valiendo',
    ];

    public const AUDIENCIAS = ['creator' => 'Creadores', 'client' => 'Clientes'];

    public static function codigo(): string
    {
        return (string) config('latam.terminos.creador', 'creator_terms');
    }

    /** La versión vigente de un documento, o `null` si no hay ninguna publicada. */
    public static function vigente(string $codigo): ?object
    {
        return DB::table('terms_versions')
            ->where('code', $codigo)
            ->whereNull('effective_to')
            ->whereNotNull('published_at')
            ->first();
    }

    /**
     * Todas las versiones de un documento, la más reciente arriba.
     *
     * @return Collection<int, \stdClass>
     */
    public static function versiones(string $codigo): Collection
    {
        return DB::table('terms_versions as tv')
            ->leftJoin('users as u', 'u.id', '=', 'tv.published_by_user_id')
            ->where('tv.code', $codigo)
            ->orderByRaw('tv.published_at IS NULL DESC')
            ->orderByDesc('tv.effective_from')
            ->orderByDesc('tv.id')
            ->get(['tv.id', 'tv.uuid', 'tv.version', 'tv.title', 'tv.audience',
                'tv.effective_from', 'tv.effective_to', 'tv.published_at',
                'tv.review_status', 'tv.review_note', 'tv.change_type',
                'tv.content_sha256', 'u.name as publicador',
                DB::raw('CHAR_LENGTH(tv.body) as largo'),
                DB::raw('(SELECT COUNT(*) FROM terms_acceptances ta '
                    .'WHERE ta.terms_version_id = tv.id) as aceptaciones')]);
    }

    public static function porUuid(string $uuid): object
    {
        $version = DB::table('terms_versions')->where('uuid', $uuid)->first();

        if ($version === null) {
            throw new RuntimeException('No existe esa version de los terminos.');
        }

        return $version;
    }

    /**
     * Las versiones cuya aceptación cuenta hoy.
     *
     * La vigente siempre; y hacia atrás, cada anterior mientras la que la
     * reemplazó se declaró **menor**. Un cambio de fondo corta la cadena.
     *
     * @return list<int>
     */
    public static function versionesQueValen(string $codigo): array
    {
        $vigente = self::vigente($codigo);

        if ($vigente === null) {
            return [];
        }

        $valen = [(int) $vigente->id];
        $actual = $vigente;

        // El tope no es decoracion: una cadena circular por un dato corrupto
        // colgaria la pantalla de activacion de todos los creadores.
        for ($vuelta = 0; $vuelta < 50; $vuelta++) {
            if ((string) ($actual->change_type ?? '') !== 'menor'
                || $actual->supersedes_version_id === null) {
                break;
            }

            $anterior = DB::table('terms_versions')
                ->where('id', $actual->supersedes_version_id)
                ->first(['id', 'change_type', 'supersedes_version_id']);

            if ($anterior === null) {
                break;
            }

            $valen[] = (int) $anterior->id;
            $actual = $anterior;
        }

        return $valen;
    }

    /** Crea un borrador. Se edita cuantas veces haga falta hasta publicarlo. */
    public static function crearBorrador(
        string $codigo,
        string $version,
        string $titulo,
        string $cuerpo,
        string $audiencia,
        int $autorId,
    ): string {
        if (trim($cuerpo) === '') {
            throw new RuntimeException('Unos terminos sin texto no se le pueden oponer a nadie.');
        }

        if (DB::table('terms_versions')->where('code', $codigo)->where('version', $version)->exists()) {
            throw new RuntimeException("Ya existe la version «{$version}» de «{$codigo}».");
        }

        $uuid = (string) Str::uuid();

        DB::table('terms_versions')->insert([
            'uuid' => $uuid,
            'audience' => $audiencia,
            'code' => $codigo,
            'version' => $version,
            'title' => mb_substr(trim($titulo), 0, 160),
            'body' => $cuerpo,
            'content_sha256' => hash('sha256', $cuerpo),
            // Un borrador no esta vigente; la fecha se fija al publicar. Se
            // guarda hoy porque la columna es NOT NULL desde la Fase 2.
            'effective_from' => now()->toDateString(),
            'review_status' => 'sin_revisar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.draft_created', tipoEntidad: 'terms_version',
            idEntidad: (int) DB::table('terms_versions')->where('uuid', $uuid)->value('id'),
            cambios: ['version' => ['antes' => null, 'despues' => $version]],
        );

        return $uuid;
    }

    /** Guarda los cambios de un borrador. Una publicada no llega aquí. */
    public static function guardarBorrador(string $uuid, string $titulo, string $cuerpo, int $autorId): void
    {
        $version = self::porUuid($uuid);

        if ($version->published_at !== null) {
            throw new RuntimeException(
                'Esa version ya esta publicada y no se reescribe. Cree la siguiente a partir de ella.',
            );
        }

        if (trim($cuerpo) === '') {
            throw new RuntimeException('Unos terminos sin texto no se le pueden oponer a nadie.');
        }

        DB::table('terms_versions')->where('uuid', $uuid)->update([
            'title' => mb_substr(trim($titulo), 0, 160),
            'body' => $cuerpo,
            'content_sha256' => hash('sha256', $cuerpo),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.draft_saved', tipoEntidad: 'terms_version',
            idEntidad: (int) $version->id,
            cambios: ['huella' => ['antes' => $version->content_sha256,
                'despues' => hash('sha256', $cuerpo)]],
        );
    }

    /**
     * Publica un borrador y cierra la versión anterior el día antes.
     *
     * `$cambio` es la declaración que decide si todo el mundo vuelve a aceptar.
     */
    public static function publicar(
        string $uuid,
        string $cambio,
        ?string $desde,
        int $autorId,
        ?int $diasParaAceptar = null,
        ?int $diasDeSoloLectura = null,
    ): void {
        $version = self::porUuid($uuid);

        if ($version->published_at !== null) {
            throw new RuntimeException('Esa version ya estaba publicada.');
        }

        if (!array_key_exists($cambio, self::CAMBIO)) {
            throw new RuntimeException('Hay que declarar si el cambio es de fondo o menor.');
        }

        $vigente = self::vigente((string) $version->code);
        $desde = $desde ?: now()->toDateString();

        // El orden de los argumentos importa y se equivoco a la primera: primero
        // la fecha de la NUEVA, despues la de la que releva.
        if ($vigente !== null && !Vigencia::puedeRelevar($desde, (string) $vigente->effective_from)) {
            throw new RuntimeException(
                "La version vigente empieza el {$vigente->effective_from}: la nueva no puede entrar antes.",
            );
        }

        DB::transaction(function () use ($version, $vigente, $cambio, $desde, $autorId,
            $diasParaAceptar, $diasDeSoloLectura): void {
            if ($vigente !== null) {
                DB::table('terms_versions')->where('id', $vigente->id)->update([
                    'effective_to' => Vigencia::cerrarElDiaAntesDe($desde),
                    'updated_at' => now(),
                ]);
            }

            DB::table('terms_versions')->where('id', $version->id)->update([
                'effective_from' => $desde,
                'published_at' => now(),
                'published_by_user_id' => $autorId,
                // La primera version no reemplaza a nadie, y entonces no hay
                // nada que declarar: `ck_terms_change_type` admite el nulo.
                'change_type' => $vigente === null ? null : $cambio,
                'supersedes_version_id' => $vigente?->id,
                // 9.19: los plazos se fijan AL PUBLICAR y despues son
                // inmutables (`tg_terms_inmutable`). Si no se dicen, valen los
                // de la columna, que es lo configurable (DEC-216).
                'acceptance_days' => $diasParaAceptar ?? $version->acceptance_days,
                'readonly_days' => $diasDeSoloLectura ?? $version->readonly_days,
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'terms.published', tipoEntidad: 'terms_version',
            idEntidad: (int) $version->id,
            cambios: [
                'version' => ['antes' => $vigente->version ?? null, 'despues' => $version->version],
                'cambio' => ['antes' => null, 'despues' => $vigente === null ? 'primera' : $cambio],
            ],
        );
    }

    /** El estado de revisión legal. Es un dato sobre el texto, no el texto. */
    public static function marcarRevision(string $uuid, string $estado, ?string $nota, int $autorId): void
    {
        if (!array_key_exists($estado, self::REVISION)) {
            throw new RuntimeException('Estado de revision no valido.');
        }

        $version = self::porUuid($uuid);

        DB::table('terms_versions')->where('uuid', $uuid)->update([
            'review_status' => $estado,
            'review_note' => $nota === null ? null : mb_substr(trim($nota), 0, 255),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.review_marked', tipoEntidad: 'terms_version',
            idEntidad: (int) $version->id,
            cambios: ['revision' => ['antes' => $version->review_status, 'despues' => $estado]],
        );
    }

    // ------------------------------------------- volver a aceptar (9.19)

    /** Está al día: no hay nada que aceptar. */
    public const AL_DIA = 'al_dia';

    /** Hay una versión sin aceptar y todavía está dentro del plazo. */
    public const PENDIENTE = 'pendiente';

    /** Se pasó el plazo: puede mirar, no puede tocar. */
    public const SOLO_LECTURA = 'solo_lectura';

    /** Se pasó también la ventana de sólo lectura: sólo la pantalla de aceptar. */
    public const BLOQUEADO = 'bloqueado';

    /**
     * Qué le pasa a este creador con los términos vigentes (9.19).
     *
     * ### El reloj no empieza cuando se publica: empieza cuando le toca a él
     *
     * Un creador que se activó **ayer** no puede aparecer bloqueado por una
     * versión de hace tres meses. Su plazo cuenta desde el día en que se activó,
     * o desde la fecha de la versión si ésta es posterior — lo que llegue más
     * tarde.
     *
     * Sin esto, el primer creador que se diera de alta después de un cambio de
     * fondo entraría **directamente al muro**, sin haber tenido un solo día. Es
     * la clase de fallo que no se ve hasta que le pasa a una persona de verdad.
     *
     * ### Y si no hay términos publicados, no hay nada que aceptar
     *
     * `DEC-190`: una configuración que falta no bloquea a nadie. Un sistema sin
     * términos deja a todo el mundo `al_dia`, y el panel de configuración lo
     * dice en rojo, que es donde tiene que decirse.
     *
     * @return array{estado: string, version: ?object, desde: ?string, limite: ?string,
     *               finLectura: ?string, dias: int}
     */
    public static function estadoDe(int $creadorId, ?string $hoy = null): array
    {
        $hoy ??= now()->toDateString();
        $sinNada = ['estado' => self::AL_DIA, 'version' => null, 'desde' => null,
            'limite' => null, 'finLectura' => null, 'dias' => 0];

        $vigente = self::vigente(self::codigo());

        if ($vigente === null) {
            return $sinNada;
        }

        $aceptada = DB::table('terms_acceptances')
            ->where('subject_type', 'creator')
            ->where('subject_id', $creadorId)
            ->whereIn('terms_version_id', self::versionesQueValen(self::codigo()))
            ->exists();

        if ($aceptada) {
            return $sinNada;
        }

        $creador = DB::table('creators')->where('id', $creadorId)
            ->first(['activated_at', 'created_at']);

        // El mas TARDE de los dos. `activated_at` puede ser nulo --un creador
        // que no llego a activarse-- y entonces vale su fecha de alta.
        $suyo = (string) ($creador->activated_at ?? $creador->created_at ?? $hoy);
        // Todo el calculo de dias pasa por `Vigencia`. La primera version lo
        // hizo con `CarbonImmutable` aqui mismo y la puerta de vigencias lo
        // caza: seria el noveno sitio del proyecto donde el error de un dia
        // puede volver a aparecer.
        $desde = max(
            Vigencia::fecha((string) $vigente->effective_from),
            Vigencia::fecha(mb_substr($suyo, 0, 10)),
        );

        $limite = Vigencia::masDias($desde, (int) $vigente->acceptance_days);
        $finLectura = Vigencia::masDias($limite, (int) $vigente->readonly_days);
        $hoy = Vigencia::fecha($hoy);

        if ($hoy <= $limite) {
            $estado = self::PENDIENTE;
            $dias = Vigencia::diasEntre($hoy, $limite);
        } elseif ($hoy <= $finLectura) {
            $estado = self::SOLO_LECTURA;
            $dias = Vigencia::diasEntre($hoy, $finLectura);
        } else {
            $estado = self::BLOQUEADO;
            $dias = 0;
        }

        return ['estado' => $estado, 'version' => $vigente, 'desde' => $desde,
            'limite' => $limite, 'finLectura' => $finLectura, 'dias' => $dias];
    }

    /**
     * Registra que este creador acepta la versión vigente, por el portal.
     *
     * `channel = 'portal'` es el único que no exige un revisor y un archivo de
     * respaldo (`ck_terms_acceptances_backing`): lo hizo el interesado con su
     * sesión, y de eso quedan la IP y el navegador.
     */
    public static function aceptar(int $creadorId, ?string $ip, ?string $navegador): void
    {
        $vigente = self::vigente(self::codigo());

        if ($vigente === null) {
            throw new RuntimeException('No hay ninguna version publicada que aceptar.');
        }

        // `uq_terms_acceptances_subject` impide la fila repetida; se comprueba
        // antes para poder no hacer nada en vez de dejar salir un 1062. Aceptar
        // dos veces no es un error del usuario: es pulsar dos veces.
        $ya = DB::table('terms_acceptances')
            ->where('subject_type', 'creator')->where('subject_id', $creadorId)
            ->where('terms_version_id', $vigente->id)->exists();

        if ($ya) {
            return;
        }

        DB::table('terms_acceptances')->insert([
            'uuid' => (string) Str::uuid(),
            'terms_version_id' => $vigente->id,
            'subject_type' => 'creator',
            'subject_id' => $creadorId,
            'channel' => 'portal',
            // La columna es VARBINARY(16): cabe una IPv6 empaquetada, no el
            // texto. Una IP invalida se guarda como nula en vez de reventar.
            'ip_address' => $ip === null ? null : (@inet_pton($ip) ?: null),
            'user_agent' => $navegador === null ? null : mb_substr($navegador, 0, 255),
            'accepted_at' => now(),
            'created_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.accepted', tipoEntidad: 'creator', idEntidad: $creadorId,
            cambios: ['version' => ['antes' => null, 'despues' => $vigente->version]],
        );
    }

    /**
     * Cuántos creadores activos están en cada estado, para el panel.
     *
     * @return array{pendientes: int, solo_lectura: int, bloqueados: int}
     */
    public static function recuentoDeReaceptacion(): array
    {
        $recuento = ['pendientes' => 0, 'solo_lectura' => 0, 'bloqueados' => 0];

        if (self::vigente(self::codigo()) === null) {
            return $recuento;
        }

        foreach (DB::table('creators')->where('status', 'active')->pluck('id') as $id) {
            match (self::estadoDe((int) $id)['estado']) {
                self::PENDIENTE => $recuento['pendientes']++,
                self::SOLO_LECTURA => $recuento['solo_lectura']++,
                self::BLOQUEADO => $recuento['bloqueados']++,
                default => null,
            };
        }

        return $recuento;
    }

    /**
     * Lo que hay que mirar de los términos, con su prioridad.
     *
     * **No bloquea nada**: informa. Es la pieza que alimenta el badge del menú,
     * y el criterio es el de `9.16`: una configuración se rellena con un valor
     * de partida y se avisa de que conviene revisarlo, no se convierte en una
     * puerta cerrada.
     *
     * @return list<array{nivel: string, texto: string}>
     */
    public static function avisos(): array
    {
        $avisos = [];
        $codigo = self::codigo();
        $vigente = self::vigente($codigo);

        if ($vigente === null) {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => 'No hay ninguna versión publicada: ningún creador puede activarse.'];

            return $avisos;
        }

        if ((string) $vigente->review_status === 'sin_revisar') {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => 'La versión vigente ('.$vigente->version.') no la ha revisado un abogado. '
                    .'Funciona, pero es el texto que se le opone a un creador si hay discusión.'];
        } elseif ((string) $vigente->review_status === 'en_revision') {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => 'La versión vigente ('.$vigente->version.') está en revisión legal.'];
        }

        // Los marcadores del borrador base. Se buscan en el texto de verdad y
        // no en una lista escrita a mano: asi el aviso desaparece solo cuando
        // alguien los resuelve.
        $pendientes = preg_match_all('/\[REVISAR/', (string) $vigente->body);

        if ($pendientes > 0) {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => "El texto vigente tiene {$pendientes} marcas «[REVISAR]» sin resolver."];
        }

        // 9.19: quien todavia no ha aceptado. Es lo que convierte «se publico
        // una version de fondo» en un dato accionable: sin esto, nadie sabe
        // cuanta gente esta a punto de quedarse en solo lectura.
        $recuento = self::recuentoDeReaceptacion();

        if ($recuento['bloqueados'] > 0) {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => $recuento['bloqueados'].' '
                    .($recuento['bloqueados'] === 1 ? 'creador no puede' : 'creadores no pueden')
                    .' entrar hasta que acepten los términos vigentes.'];
        }

        if ($recuento['solo_lectura'] > 0) {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => $recuento['solo_lectura'].' '
                    .($recuento['solo_lectura'] === 1 ? 'creador está' : 'creadores están')
                    .' en sólo lectura por no haber aceptado a tiempo.'];
        }

        if ($recuento['pendientes'] > 0) {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => $recuento['pendientes'].' '
                    .($recuento['pendientes'] === 1 ? 'creador todavía no ha aceptado' : 'creadores todavía no han aceptado')
                    .' la versión vigente, y siguen dentro de plazo.'];
        }

        return $avisos;
    }
}
