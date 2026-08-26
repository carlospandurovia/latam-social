<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A quién se le puede ofrecer esta campaña (7.4).
 *
 * ### Los filtros no se teclean: salen de la campaña
 *
 * Esta es la decisión de diseño de la iteración. Un buscador de creadores con
 * quince casillas vacías obliga al operador a **recordar** las reglas: que la
 * campaña corre en Perú y Colombia, que pide reels, que la marca es de alcohol
 * y que hay una edad mínima. Recordar cuatro cosas a la vez es no recordar una.
 *
 * Así que el buscador **parte de la campaña**: los mercados, los formatos del
 * brief, la edad mínima y las categorías de la marca ya están aplicados cuando
 * la pantalla se abre. Lo que se teclea encima —texto, categoría, red, tope de
 * tarifa— es refinamiento, no la regla.
 *
 * ### Dos clases de filtro, y la diferencia importa
 *
 * | Clase | Qué hace | Ejemplo |
 * |---|---|---|
 * | **Duro** | quita al creador de la lista | no está en ningún mercado de la campaña |
 * | **Blando** | lo deja pero lo marca | le falta un requisito de `BR-CREATOR-006` |
 *
 * Un filtro duro que no se puede explicar es un creador que desaparece sin
 * motivo, y eso parece un fallo del sistema. Por eso existe `conDescartados()`:
 * la misma consulta sin los filtros duros, con **el motivo de cada descarte**.
 * Sirve para contestar «¿por qué no me sale Fulano?» sin abrir la base.
 *
 * ### Lo que NO se filtra aquí (decisión de negocio, 2026-08-25)
 *
 * La completitud operativa de `BR-CREATOR-006`. Un creador activado en junio al
 * que en agosto se le retiró el medio de pago sigue siendo `active`, y **sale en
 * la búsqueda** con lo que le falta marcado. El veto real está en
 * `ListaCorta::vetoParaAnadir()`, que es donde importa.
 *
 * El motivo: revalidar seis requisitos por cada candidato en cada búsqueda es
 * caro, y esconder a alguien sin decir por qué es peor que enseñarlo con una
 * etiqueta. Se ve que existe, se ve qué le falta, y no se puede meter hasta que
 * se arregle.
 */
final class BuscadorDeCreadores
{
    /**
     * Los candidatos, ya filtrados por la campaña.
     *
     * @param array<string, mixed> $filtros texto, categoria, formato, plataforma, tarifa_max
     * @return Collection<int, \stdClass>
     */
    public static function buscar(object $campana, array $filtros = []): Collection
    {
        $consulta = self::base($campana);
        self::aplicarDuros($consulta, $campana);
        self::aplicarBlandos($consulta, $filtros);

        return self::rematar($consulta, $campana);
    }

    /**
     * Los descartados por los filtros duros, con el motivo.
     *
     * Cada motivo es una subconsulta con su nombre. Se calculan **todos** y no
     * se para en el primero: «no está en el mercado» y «declaró que no trabaja
     * esta categoría» son dos conversaciones distintas con el creador, y saber
     * sólo la primera lleva a tenerla dos veces.
     *
     * @param array<string, mixed> $filtros
     * @return Collection<int, \stdClass>
     */
    public static function conDescartados(object $campana, array $filtros = []): Collection
    {
        $consulta = self::base($campana);
        self::aplicarBlandos($consulta, $filtros);

        foreach (self::duros($campana) as $nombre => $condicion) {
            $consulta->selectRaw(
                'CASE WHEN '.$condicion['sql'].' THEN 0 ELSE 1 END AS `descarte_'.$nombre.'`',
                $condicion['datos'],
            );
        }

        return self::rematar($consulta, $campana);
    }

    /** Los nombres de los descartes, con su explicación para la pantalla. */
    public const MOTIVOS = [
        'mercado' => 'su pais no es un mercado de la campana',
        'edad' => 'no llega a la edad minima el dia que empieza la campana',
        'formato' => 'no ofrece ninguno de los formatos del brief',
        'restriccion' => 'declaro que no trabaja con alguna categoria de esta marca',
        'agenda' => 'tiene la agenda bloqueada durante la campana',
        'yaEsta' => 'ya esta en esta campana',
    ];

    /**
     * La edad mínima efectiva de la campaña.
     *
     * Es el **máximo** entre lo que pide la campaña y lo que piden las
     * categorías de la marca (`BR-CREATOR-012`). Una campaña de una marca de
     * bebidas con `min_creator_age = 0` no es una campaña sin edad mínima: es
     * una campaña que hereda la de su categoría.
     *
     * Nota sobre `BR-CREATOR-012`: el texto de `docs/06` sólo habla de creadores
     * **con tutela activa**, y `min_creator_age` es una columna de la campaña
     * que no menciona la tutela. Aplicarla sólo a los menores dejaría pasar a un
     * creador de 20 años en una campaña de 21. Se aplica a todos, y queda
     * anotado como `T-34` que el texto de la regla dice menos de lo que el
     * esquema implica.
     */
    public static function edadMinima(object $campana): int
    {
        $deLaMarca = (int) DB::table('client_brand_categories as cbc')
            ->join('categories as cat', 'cat.id', '=', 'cbc.category_id')
            ->where('cbc.client_brand_id', $campana->client_brand_id)
            ->max('cat.min_age');

        return max((int) $campana->min_creator_age, $deLaMarca);
    }

    /**
     * Cuánto costaría este creador, con las tarifas vigentes hoy.
     *
     * Devuelve `[creador_id => ['importe' => float|null, 'aviso' => string|null]]`.
     * Un `importe` nulo **no es cero**: es que no se puede calcular, y el aviso
     * dice por qué. Las dos razones son distintas y las dos hay que verlas:
     *
     * - le faltan tarifas para alguno de los formatos que sí ofrece;
     * - las tiene en otra moneda, y aquí no se convierte nada.
     *
     * No se convierte a propósito. Hay `exchange_rates` en el esquema y **nadie
     * los mantiene todavía** (llega en 9.1): convertir con una tabla vacía o
     * vieja daría un número con pinta de presupuesto y sin nada detrás.
     *
     * @param list<int> $creadorIds
     * @return array<int, array{importe: float|null, aviso: string|null, formatos: int}>
     */
    public static function costeEstimado(object $campana, array $creadorIds): array
    {
        if ($creadorIds === []) {
            return [];
        }

        $requisitos = Campanas::requisitos((int) $campana->id)
            ->groupBy('content_format_id')
            ->map(fn ($filas) => (int) $filas->max('quantity'));

        if ($requisitos->isEmpty()) {
            return array_fill_keys($creadorIds, [
                'importe' => null, 'aviso' => 'el brief todavia no pide ningun formato', 'formatos' => 0,
            ]);
        }

        $tarifas = DB::table('creator_rates')
            ->whereIn('creator_id', $creadorIds)
            ->whereIn('content_format_id', $requisitos->keys()->all())
            ->whereNotNull('current_gate')
            ->get(['creator_id', 'content_format_id', 'currency_code', 'amount', 'is_gratis']);

        $ofrecidos = DB::table('creator_formats')
            ->whereIn('creator_id', $creadorIds)
            ->whereIn('content_format_id', $requisitos->keys()->all())
            ->where('is_offered', 1)
            ->get(['creator_id', 'content_format_id'])
            ->groupBy('creator_id');

        $salida = [];

        foreach ($creadorIds as $id) {
            $suyos = $ofrecidos->get($id, collect())->pluck('content_format_id')->map(fn ($x) => (int) $x);
            $misTarifas = $tarifas->where('creator_id', $id)->keyBy('content_format_id');

            $importe = 0.0;
            $sinTarifa = 0;
            $otraMoneda = 0;

            foreach ($suyos as $formatoId) {
                $t = $misTarifas->get($formatoId);

                if ($t === null) {
                    $sinTarifa++;

                    continue;
                }

                if ((string) $t->currency_code !== (string) $campana->currency_code) {
                    $otraMoneda++;

                    continue;
                }

                $importe += (float) $t->amount * $requisitos[$formatoId];
            }

            $aviso = match (true) {
                $suyos->isEmpty() => 'no ofrece ninguno de los formatos del brief',
                $otraMoneda > 0 => "tiene {$otraMoneda} tarifa(s) en otra moneda: aqui no se convierte nada",
                $sinTarifa > 0 => "le faltan {$sinTarifa} tarifa(s) de los formatos que ofrece",
                default => null,
            };

            $salida[$id] = [
                'importe' => $aviso === null ? $importe : null,
                'aviso' => $aviso,
                'formatos' => $suyos->count(),
            ];
        }

        return $salida;
    }

    // ------------------------------------------------------------------ apoyo

    private static function base(object $campana): Builder
    {
        return DB::table('creators as c')
            ->join('countries as p', 'p.id', '=', 'c.country_id')
            ->where('c.status', 'active')
            ->whereNull('c.anonymized_at')
            ->select([
                'c.id', 'c.uuid', 'c.display_name', 'c.country_id', 'c.city',
                'c.preferred_currency_code', 'p.name as pais', 'p.iso2',
            ]);
    }

    /**
     * Los filtros duros, cada uno con su SQL y su nombre.
     *
     * Se declaran una vez y se usan en los dos caminos —aplicar y explicar—
     * porque una lista de descartes que no sea EXACTAMENTE la misma que el
     * filtro miente sobre por qué falta alguien. Es la misma razón por la que
     * `SUITES` vive en un solo fichero.
     *
     * @return array<string, array{sql: string, datos: list<mixed>}>
     */
    private static function duros(object $campana): array
    {
        $formatos = Campanas::requisitos((int) $campana->id)
            ->pluck('content_format_id')->unique()->values()->all();

        return [
            'mercado' => [
                'sql' => 'EXISTS (SELECT 1 FROM campaign_markets m
                            WHERE m.campaign_id = ? AND m.country_id = c.country_id)',
                'datos' => [$campana->id],
            ],
            'edad' => [
                'sql' => 'TIMESTAMPDIFF(YEAR, c.birth_date, ?) >= ?',
                'datos' => [$campana->starts_on, self::edadMinima($campana)],
            ],
            'formato' => $formatos === [] ? ['sql' => '1 = 1', 'datos' => []] : [
                'sql' => 'EXISTS (SELECT 1 FROM creator_formats cf
                            WHERE cf.creator_id = c.id AND cf.is_offered = 1
                              AND cf.content_format_id IN ('.implode(',', array_fill(0, count($formatos), '?')).'))',
                'datos' => $formatos,
            ],
            // Media `BR-CAMPAIGN-007`, y la mitad que YA se puede comprobar: el
            // creador declaro por escrito que no trabaja esa categoria. Invitarlo
            // es hacerle perder el tiempo a los dos. El resto de la regla
            // --competidores y exclusividades vigentes-- llega en 7.11.
            'restriccion' => [
                'sql' => 'NOT EXISTS (SELECT 1 FROM creator_restrictions cr
                            JOIN client_brand_categories cbc ON cbc.category_id = cr.category_id
                            WHERE cr.creator_id = c.id AND cbc.client_brand_id = ?)',
                'datos' => [$campana->client_brand_id],
            ],
            // Dos periodos NO se solapan si uno termina antes de que el otro
            // empiece. Se escribe por la negacion porque enumerar los cuatro
            // casos de solape es donde se cuela el error de un dia.
            'agenda' => [
                'sql' => 'NOT EXISTS (SELECT 1 FROM creator_blackouts b
                            WHERE b.creator_id = c.id
                              AND NOT (b.ends_on < ? OR b.starts_on > ?))',
                'datos' => [$campana->starts_on, $campana->ends_on],
            ],
            'yaEsta' => [
                'sql' => 'NOT EXISTS (SELECT 1 FROM campaign_creators cc
                            WHERE cc.campaign_id = ? AND cc.creator_id = c.id)',
                'datos' => [$campana->id],
            ],
        ];
    }

    private static function aplicarDuros(Builder $consulta, object $campana): void
    {
        foreach (self::duros($campana) as $condicion) {
            $consulta->whereRaw($condicion['sql'], $condicion['datos']);
        }
    }

    /** @param array<string, mixed> $filtros */
    private static function aplicarBlandos(Builder $consulta, array $filtros): void
    {
        if (($texto = trim((string) ($filtros['texto'] ?? ''))) !== '') {
            // `like` con comodin a los dos lados no usa indice, y da igual: son
            // 150 creadores en el MVP. Cuando sean 10.000 esto es un indice de
            // texto completo, no una reescritura.
            $consulta->where(function (Builder $q) use ($texto): void {
                $q->where('c.display_name', 'like', "%{$texto}%")
                    ->orWhereExists(fn (Builder $s) => $s->from('social_accounts as sa')
                        ->whereColumn('sa.creator_id', 'c.id')
                        ->where('sa.handle', 'like', "%{$texto}%"));
            });
        }

        if (($categoria = (int) ($filtros['categoria'] ?? 0)) > 0) {
            $consulta->whereExists(fn (Builder $s) => $s->from('creator_categories as cc')
                ->whereColumn('cc.creator_id', 'c.id')->where('cc.category_id', $categoria));
        }

        if (($formato = (int) ($filtros['formato'] ?? 0)) > 0) {
            $consulta->whereExists(fn (Builder $s) => $s->from('creator_formats as cf')
                ->whereColumn('cf.creator_id', 'c.id')
                ->where('cf.content_format_id', $formato)->where('cf.is_offered', 1));
        }

        if (($plataforma = (int) ($filtros['plataforma'] ?? 0)) > 0) {
            // La red tiene que estar VERIFICADA: una cuenta declarada y sin
            // verificar no es una audiencia, es una afirmacion.
            $consulta->whereExists(fn (Builder $s) => $s->from('social_accounts as sa')
                ->whereColumn('sa.creator_id', 'c.id')
                ->where('sa.platform_id', $plataforma)
                ->where('sa.is_active', 1)
                ->whereNotNull('sa.verified_gate'));
        }
    }

    /** @return Collection<int, \stdClass> */
    private static function rematar(Builder $consulta, object $campana): Collection
    {
        return $consulta
            ->selectRaw('TIMESTAMPDIFF(YEAR, c.birth_date, ?) AS `edad`', [$campana->starts_on])
            ->orderBy('c.display_name')
            ->limit(200)
            ->get();
    }
}
