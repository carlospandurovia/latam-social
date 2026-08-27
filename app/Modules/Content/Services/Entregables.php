<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Campaign\Services\Mercados;
use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\Eventos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que un creador tiene que entregar, y cómo lo entrega (8.1).
 *
 * ### Se generan solos al aceptar
 *
 * Decisión de negocio (2026-08-26). El brief ya lo dice todo —cuántos, de qué
 * formato, con cuántos días de plazo— así que pedirle a alguien que lo teclee
 * otra vez por cada creador es pedirle que copie un dato que el sistema ya tiene.
 *
 * Y el modo de fallo de la alternativa es el caro: el día que se olvide teclearlo,
 * ese creador **no tiene nada que entregar y nadie se entera** hasta que la
 * campaña termina sin su contenido.
 *
 * ### El brief que se usa es el EFECTIVO, no el general
 *
 * `Mercados::briefEfectivo()`, que ya resuelve `N-03`: si el mercado del creador
 * tiene brief propio, ése reemplaza al general. Un creador peruano en una campaña
 * con brief específico para Perú recibe el de Perú, y las etiquetas también — que
 * es justo por lo que `7.2` dejó los hashtags fuera hasta que existieran los
 * mercados.
 *
 * ### Las etiquetas se comprueban al enviar, no en la revisión
 *
 * Decisión de negocio (2026-08-26): si el caption no lleva los hashtags o las
 * menciones que pide el brief, **no se envía**, y se le dice cuáles faltan.
 *
 * Es una comprobación objetiva y barata que ahorra una ronda de corrección
 * entera: el creador lo arregla en diez segundos en vez de enterarse tres días
 * después. La alternativa —avisar y dejar pasar— acaba siempre en un aviso que
 * nadie lee, y la de dejarlo para la revisión traslada a una persona la clase de
 * tarea que una máquina hace mejor.
 */
final class Entregables
{
    public const PENDIENTE = 'pending';

    public const ENVIADO = 'submitted';

    /** Los estados desde los que el creador todavía puede mandar una versión. */
    public const ABIERTOS = ['pending', 'in_production', 'submitted', 'in_review', 'changes_requested'];

    /**
     * Crea los entregables de una participación a partir de su brief efectivo.
     *
     * Devuelve cuántos creó. **Es idempotente**: si ya los tiene, no crea
     * ninguno más. Aceptar dos veces no puede pasar —el enlace es de un solo
     * uso— pero una reanudación de la cola o un reintento manual sí, y duplicar
     * el trabajo de un creador es la clase de error que se descubre tarde y mal.
     */
    public static function generarPara(int $participacionId): int
    {
        $participacion = DB::table('campaign_creators as cc')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('cc.id', $participacionId)
            ->first([
                'cc.id', 'cc.campaign_id', 'cc.campaign_market_id', 'cc.accepted_at',
                'c.starts_on',
            ]);

        if ($participacion === null || $participacion->accepted_at === null) {
            return 0;
        }

        if (DB::table('deliverables')->where('campaign_creator_id', $participacionId)->exists()) {
            return 0;
        }

        $requisitos = Mercados::briefEfectivo(
            (int) $participacion->campaign_id,
            $participacion->campaign_market_id === null ? null : (int) $participacion->campaign_market_id,
        );

        $creados = 0;

        DB::transaction(function () use ($participacion, $requisitos, &$creados): void {
            foreach ($requisitos as $requisito) {
                for ($n = 1; $n <= (int) $requisito->quantity; $n++) {
                    DB::table('deliverables')->insert([
                        'uuid' => (string) Str::uuid(),
                        'campaign_creator_id' => $participacion->id,
                        'campaign_requirement_id' => $requisito->id,
                        'sequence_number' => $n,
                        'status' => self::PENDIENTE,
                        'due_on' => self::fechaLimite($participacion, $requisito),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $creados++;
                }
            }
        });

        if ($creados > 0) {
            Eventos::ocurrio(
                nombre: 'campaign_creator.deliverables_created',
                tipoEntidad: 'campaign_creator',
                idEntidad: (int) $participacion->id,
                payload: ['cuantos' => $creados],
            );
        }

        return $creados;
    }

    /**
     * Lo que le toca entregar a una participación, con su requisito al lado.
     *
     * @return Collection<int, \stdClass>
     */
    public static function de(int $participacionId): Collection
    {
        return DB::table('deliverables as d')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'f.platform_id')
            ->where('d.campaign_creator_id', $participacionId)
            ->orderBy('d.due_on')
            ->orderBy('f.code')
            ->orderBy('d.sequence_number')
            ->get([
                'd.id', 'd.uuid', 'd.status', 'd.due_on', 'd.sequence_number',
                'd.submitted_at', 'd.approved_at', 'd.approved_version_id',
                'r.quantity', 'r.notes', 'r.hashtags', 'r.mentions',
                'r.permanence_days', 'f.code as formato', 'p.name as red',
            ]);
    }

    /**
     * Las versiones de un entregable, la más reciente primero.
     *
     * @return Collection<int, \stdClass>
     */
    public static function versiones(int $entregableId): Collection
    {
        return DB::table('deliverable_versions as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.submitted_by_user_id')
            ->leftJoin('files as fi', 'fi.id', '=', 'v.file_id')
            ->where('v.deliverable_id', $entregableId)
            ->orderByDesc('v.version_number')
            ->get([
                'v.uuid', 'v.version_number', 'v.external_url', 'v.caption',
                'v.creator_notes', 'v.submitted_at', 'u.name as autor',
                'fi.original_name as archivo',
            ]);
    }

    /**
     * Qué etiquetas del brief **faltan** en el caption.
     *
     * @return array{hashtags: list<string>, mentions: list<string>}
     */
    public static function faltanEtiquetas(?string $caption, object $requisito): array
    {
        $texto = mb_strtolower((string) $caption);

        $faltan = static function (?string $exigidas) use ($texto): array {
            $lista = preg_split('/\s+/', trim((string) $exigidas), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_filter(
                $lista,
                // Comparacion sin distinguir mayusculas: `#ACMEVerano` y
                // `#acmeverano` son el mismo hashtag para las plataformas, y
                // rechazar por la caja seria rechazar por algo que da igual.
                static fn (string $etiqueta): bool => !str_contains($texto, mb_strtolower($etiqueta)),
            ));
        };

        return [
            'hashtags' => $faltan($requisito->hashtags ?? null),
            'mentions' => $faltan($requisito->mentions ?? null),
        ];
    }

    /**
     * Por qué esta entrega no se puede mandar, o lista vacía.
     *
     * Devuelve **todos** los motivos: quien está entregando desde el móvil
     * prefiere una lista de tres cosas que arreglar a tres intentos.
     *
     * @param array{external_url?: ?string, caption?: ?string} $datos
     * @return list<string>
     */
    public static function vetoParaEntregar(object $entregable, array $datos, ?int $archivoId): array
    {
        $motivos = [];

        if (!in_array((string) $entregable->status, self::ABIERTOS, true)) {
            return [sprintf('Este entregable esta en «%s» y ya no admite envios.', $entregable->status)];
        }

        // El requisito se BUSCA aqui, no se confia en que venga dentro de
        // `$entregable`.
        //
        // La primera version leia `$entregable->hashtags` y `->mentions`, que es
        // lo que trae la consulta del portal del creador pero NO lo que trae una
        // fila cruda de `deliverables`. Con una fila cruda no fallaba: devolvia
        // «no falta nada» y dejaba pasar un caption sin las etiquetas. Un veto
        // que se desactiva segun como te llamen es peor que no tenerlo, porque
        // parece que esta.
        $requisito = self::requisitoDe((int) $entregable->id);

        $enlace = trim((string) ($datos['external_url'] ?? ''));

        // `ck_dv_content` exige archivo o enlace. Se dice con palabras antes de
        // que la base lo diga con un 3819.
        if ($enlace === '' && $archivoId === null) {
            $motivos[] = 'Manda el enlace a tu contenido, o sube una imagen.';
        }

        if ($enlace !== '' && !str_starts_with($enlace, 'https://')) {
            $motivos[] = 'El enlace tiene que empezar por https://';
        }

        $faltan = self::faltanEtiquetas($datos['caption'] ?? null, $requisito);

        if ($faltan['hashtags'] !== []) {
            $motivos[] = 'Al texto le faltan estos hashtags: '.implode(' ', $faltan['hashtags']);
        }

        if ($faltan['mentions'] !== []) {
            $motivos[] = 'Al texto le faltan estas menciones: '.implode(' ', $faltan['mentions']);
        }

        return $motivos;
    }

    /**
     * Manda una versión. Devuelve su número.
     *
     * **Append-only**: nunca se edita la anterior. `uq_dv_number` lo garantiza en
     * la base, y por eso el número se calcula dentro de la transacción — dos
     * envíos simultáneos del mismo entregable chocarían con un `1062` en vez de
     * pisarse, que es exactamente lo que queremos.
     *
     * @param array{external_url?: ?string, caption?: ?string, creator_notes?: ?string} $datos
     */
    public static function entregar(
        object $entregable,
        array $datos,
        ?int $archivoId,
        ?int $usuarioId,
        ?string $ip,
    ): int {
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;
        $enlace = trim((string) ($datos['external_url'] ?? ''));

        $numero = DB::transaction(function () use ($entregable, $datos, $enlace, $archivoId, $usuarioId, $empaquetada): int {
            $siguiente = 1 + (int) DB::table('deliverable_versions')
                ->where('deliverable_id', $entregable->id)
                ->lockForUpdate()
                ->max('version_number');

            DB::table('deliverable_versions')->insert([
                'uuid' => (string) Str::uuid(),
                'deliverable_id' => $entregable->id,
                'version_number' => $siguiente,
                'file_id' => $archivoId,
                'external_url' => $enlace !== '' ? $enlace : null,
                'caption' => $datos['caption'] ?? null,
                'creator_notes' => $datos['creator_notes'] ?? null,
                'submitted_at' => now(),
                'submitted_by_user_id' => $usuarioId,
                'submitted_ip' => $empaquetada === false ? null : $empaquetada,
                'created_at' => now(),
            ]);

            DB::table('deliverables')->where('id', $entregable->id)->update([
                'status' => self::ENVIADO,
                // La PRIMERA vez. `submitted_at` responde «.cuando entrego?», y
                // una correccion posterior no cambia esa fecha: para eso esta el
                // `submitted_at` de cada version.
                'submitted_at' => $entregable->submitted_at ?? now(),
                'updated_at' => now(),
            ]);

            return $siguiente;
        });

        Bitacora::registrar(
            accion: 'deliverable.submitted',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            cambios: [
                'status' => ['antes' => $entregable->status, 'despues' => self::ENVIADO],
                'version' => ['antes' => $numero - 1, 'despues' => $numero],
            ],
        );

        Eventos::ocurrio(
            nombre: 'deliverable.submitted',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            payload: ['version' => $numero],
        );

        return $numero;
    }

    /**
     * Cuántos van y cuántos faltan, para la pantalla interna.
     *
     * @return array{total: int, enviados: int, vencidos: int}
     */
    public static function avance(int $campanaId): array
    {
        $filas = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->where('cc.campaign_id', $campanaId)
            ->get(['d.status', 'd.due_on', 'd.submitted_at']);

        return [
            'total' => $filas->count(),
            'enviados' => $filas->whereNotNull('submitted_at')->count(),
            // Vencido es «sin entregar y con la fecha pasada». Un entregable
            // enviado tarde NO cuenta como vencido: ya llego, y contarlo dejaria
            // la cifra en rojo para siempre.
            'vencidos' => $filas
                ->filter(fn (object $d): bool => $d->submitted_at === null
                    && Carbon::parse((string) $d->due_on)->isBefore(now()->startOfDay()))
                ->count(),
        ];
    }

    /**
     * El requisito del que nace un entregable, con sus etiquetas.
     *
     * Devuelve un objeto vacío si el entregable no existe: quien llama ya tiene
     * su propio 404, y aquí un `null` obligaría a comprobarlo otra vez en el
     * único sitio donde ya se sabe que existe.
     */
    private static function requisitoDe(int $entregableId): object
    {
        return DB::table('deliverables as d')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->where('d.id', $entregableId)
            ->first(['r.hashtags', 'r.mentions'])
            ?? (object) ['hashtags' => null, 'mentions' => null];
    }

    /**
     * La fecha límite: el arranque de la campaña más el plazo del requisito.
     *
     * Si la campaña ya arrancó, se cuenta **desde hoy**: un plazo calculado
     * hacia atrás nace vencido, y `ck_del_due_futuro` lo rechaza — con razón.
     */
    private static function fechaLimite(object $participacion, object $requisito): string
    {
        $arranque = Carbon::parse((string) $participacion->starts_on)->startOfDay();
        $base = $arranque->isBefore(now()->startOfDay()) ? now()->startOfDay() : $arranque;

        return $base->addDays((int) $requisito->deadline_offset_days)->toDateString();
    }
}
