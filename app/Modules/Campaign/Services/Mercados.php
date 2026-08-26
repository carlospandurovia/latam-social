<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los mercados de una campaña, y qué brief le toca a cada uno (7.3).
 *
 * ### La regla de `N-03`: reemplaza, no mezcla
 *
 * `campaign_requirements.campaign_market_id NULL` significa **«todos los
 * mercados»** — la única vez en todo el modelo que un `NULL` significa algo en
 * vez de «no aplica» (2.3 §9, excepción consciente). Y la resolución es:
 *
 * > Para el mercado M, el brief efectivo son los requisitos de M **si existe al
 * > menos uno**; si no, los generales.
 *
 * Estaba escrita desde la Fase 2 y **nada la implementaba**. Es la tercera pieza
 * del mismo patrón que 7.1 y 7.2: una regla del documento sin código detrás.
 *
 * Reemplazar y no fusionar se eligió en 2.3 por una razón que sigue en pie: si
 * el general pide 3 stories y el de México pide 2, fusionar obliga a decidir si
 * son 2, 3 o 5. **Cualquier respuesta es arbitraria y se descubre en
 * producción.** Con reemplazo, la pantalla de México enseña exactamente lo que
 * alguien escribió para México.
 *
 * ### Lo que esta clase NO decide
 *
 * Que el mercado sea de la campaña. Eso lo garantiza el esquema desde 7.3, con
 * foráneas **compuestas** `(campaign_market_id, campaign_id)`: una consulta que
 * se olvide del `campaign_id` ya no puede traer el mercado de otra campaña,
 * porque esa fila no existe.
 */
final class Mercados
{
    /**
     * Los mercados de una campaña, con el nombre del país.
     *
     * @return Collection<int, \stdClass>
     */
    public static function de(int $campanaId): Collection
    {
        return DB::table('campaign_markets as m')
            ->join('countries as p', 'p.id', '=', 'm.country_id')
            ->where('m.campaign_id', $campanaId)
            ->orderBy('p.name')
            ->get(['m.id', 'm.country_id', 'm.target_creators', 'p.name as pais', 'p.iso2']);
    }

    /**
     * Los países que todavía se pueden añadir a esta campaña.
     *
     * Se restan los que ya están en vez de dejar que `uq_cm_campaign_country`
     * rechace: ofrecer una opción que se sabe que va a fallar es hacer teclear
     * al operador para decirle que no.
     *
     * @return Collection<int, \stdClass>
     */
    public static function paisesDisponibles(int $campanaId): Collection
    {
        return DB::table('countries')
            ->whereNotIn('id', fn ($q) => $q->from('campaign_markets')
                ->where('campaign_id', $campanaId)->select('country_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'iso2']);
    }

    /**
     * El brief efectivo de un mercado, aplicando `N-03`.
     *
     * `$mercadoId` a `null` devuelve los generales tal cual, que es lo que ve
     * una campaña sin mercados todavía.
     *
     * @return Collection<int, \stdClass>
     */
    public static function briefEfectivo(int $campanaId, ?int $mercadoId): Collection
    {
        $propios = $mercadoId === null
            ? collect()
            : self::requisitos($campanaId, $mercadoId);

        return $propios->isNotEmpty() ? $propios : self::requisitos($campanaId, null);
    }

    /**
     * Qué mercados tienen brief PROPIO, para poder decirlo en pantalla.
     *
     * Sin esto, dos mercados con el mismo brief se ven igual sin poder saber si
     * es porque los dos heredan el general o porque alguien escribió lo mismo
     * dos veces — y eso cambia qué pasa al editar el general.
     *
     * @return list<int>
     */
    public static function conBriefPropio(int $campanaId): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('campaign_requirements')
            ->where('campaign_id', $campanaId)
            ->whereNotNull('campaign_market_id')
            ->distinct()
            ->pluck('campaign_market_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * Por qué este mercado **no** se puede quitar, o `null` si sí.
     *
     * Dos motivos distintos y los dos importan:
     *
     * | Motivo | Por qué |
     * |---|---|
     * | La campaña está confirmada | decisión de negocio: añadir sí, quitar no |
     * | Cuelgan requisitos o participaciones | la foránea es `RESTRICT` y daría un `1451` |
     *
     * El segundo se comprueba aquí **teniendo el primero delante**: un `1451`
     * dice «no se puede borrar la fila padre» y nombra un índice, que es cierto
     * y no le sirve a nadie. Traducirlo después es traducir; decirlo antes es
     * explicar.
     */
    public static function vetoParaQuitar(object $campana, int $mercadoId): ?string
    {
        if ($campana->confirmed_at !== null) {
            return 'De una campana confirmada no se quita un mercado: puede dejar fuera a creadores '
                .'ya invitados o aceptados, y eso exige una enmienda aceptada por las dos partes '
                .'(BR-CAMPAIGN-003). Anadir un mercado nuevo si se puede.';
        }

        $requisitos = DB::table('campaign_requirements')->where('campaign_market_id', $mercadoId)->count();
        $participaciones = DB::table('campaign_creators')->where('campaign_market_id', $mercadoId)->count();

        if ($requisitos > 0 || $participaciones > 0) {
            return sprintf(
                'Ese mercado todavia tiene %s. Quitelos primero: borrar el mercado con ellos dentro '
                .'los dejaria apuntando a un pais que ya no esta en la campana.',
                implode(' y ', array_filter([
                    $requisitos > 0 ? "{$requisitos} requisito(s) de brief" : null,
                    $participaciones > 0 ? "{$participaciones} participacion(es) de creador" : null,
                ])),
            );
        }

        return null;
    }

    /**
     * Los requisitos de un ámbito concreto. `null` = los generales.
     *
     * @return Collection<int, \stdClass>
     */
    private static function requisitos(int $campanaId, ?int $mercadoId): Collection
    {
        $consulta = DB::table('campaign_requirements as r')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'f.platform_id')
            ->where('r.campaign_id', $campanaId);

        // `where(col, null)` genera `= NULL`, que en SQL no es cierto nunca.
        // Aqui NULL SIGNIFICA algo, asi que hay que preguntarlo con `IS NULL`.
        $mercadoId === null
            ? $consulta->whereNull('r.campaign_market_id')
            : $consulta->where('r.campaign_market_id', $mercadoId);

        return $consulta
            ->orderBy('p.name')->orderBy('f.code')
            ->get([
                'r.id', 'r.content_format_id', 'r.campaign_market_id', 'r.quantity',
                'r.deadline_offset_days', 'r.permanence_days', 'r.notes',
                'f.code as formato', 'p.name as red',
            ]);
    }
}
