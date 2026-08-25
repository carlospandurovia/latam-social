<?php

declare(strict_types=1);

namespace App\Modules\Client\Services;

use Illuminate\Support\Facades\DB;

/**
 * Qué sociedad puede facturar a un país, y en qué fecha (`BR-LE-003`/`BR-LE-004`).
 *
 * Es la pregunta que hay que contestar antes de prometerle nada a un cliente:
 * si ninguna sociedad del grupo cubre su país, **no se le puede emitir una
 * factura**, y descubrirlo el día de la facturación es tarde.
 *
 * `BR-LE-004` es explícita sobre cómo se contesta cuando no hay ninguna: *«la
 * operación se bloquea con un mensaje accionable. Nunca se asigna una entidad
 * por defecto ni se continúa en silencio.»* Por eso esta clase no devuelve
 * `null` a secas: devuelve **por qué** no hay, para que la pantalla pueda decir
 * qué falta y quién puede arreglarlo.
 *
 * ### Por qué aquí no hay empates
 *
 * La cobertura vive en `legal_entity_countries`, que es una tabla de periodos.
 * Hasta la iteración 3.10 dos sociedades podían cubrir el mismo país en fechas
 * solapadas: `uq_lec_country` impedía dos **vigentes a la vez**, pero no dos
 * periodos cerrados que se pisaran, y el resolver elige por país **y por
 * fecha**. Ese empate era una factura emitida por la sociedad equivocada.
 *
 * Desde que `tg_lec_sin_solape_*` existe, en cualquier fecha hay como mucho una.
 * Esta clase se apoya en eso —consulta sin desempatar— y lo comprueba: si
 * alguna vez apareciera más de una, lo dice en vez de elegir.
 */
final class CoberturaFacturacion
{
    public const HAY = 'hay';

    public const NINGUNA = 'ninguna';

    /** No debería ocurrir desde 3.10; si ocurre, hay que verlo, no taparlo. */
    public const EMPATE = 'empate';

    /**
     * @param object|null $entidad Fila de `legal_entities` cuando hay una sola.
     * @param list<object> $candidatas Todas las que cubrían, para poder explicar un empate.
     */
    private function __construct(
        public readonly string $resultado,
        public readonly ?object $entidad,
        public readonly array $candidatas,
        public readonly string $explicacion,
    ) {}

    public function hay(): bool
    {
        return $this->resultado === self::HAY;
    }

    /**
     * La sociedad que factura a `$paisId` en `$fecha`.
     *
     * `$fecha` es un parámetro y no `now()` a propósito: `BR-LE-003` dice «en la
     * fecha de la operación», y una campaña que se factura en marzo se rige por
     * la cobertura de marzo, no por la de hoy.
     */
    public static function resolver(int $paisId, ?string $fecha = null): self
    {
        $fecha ??= now()->toDateString();

        $filas = DB::table('legal_entity_countries as lec')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->where('lec.country_id', $paisId)
            ->whereDate('lec.valid_from', '<=', $fecha)
            ->where(function ($q) use ($fecha): void {
                $q->whereNull('lec.valid_to')->orWhereDate('lec.valid_to', '>=', $fecha);
            })
            ->where('le.status', 'active')
            ->orderBy('le.code')
            ->get(['le.id', 'le.code', 'le.legal_name', 'le.default_currency_code',
                'lec.coverage_basis', 'lec.valid_from', 'lec.valid_to']);

        $pais = (string) DB::table('countries')->where('id', $paisId)->value('name');

        if ($filas->isEmpty()) {
            return new self(
                self::NINGUNA,
                null,
                [],
                sprintf(
                    'Ninguna sociedad del grupo cubre la facturacion de %s al %s. Hay que dar de '
                    .'alta la cobertura en Entidades legales, diciendo desde cuando y con que '
                    .'motivo (exportacion de servicios, sociedad local...), antes de poder '
                    .'facturar a un cliente de ese pais.',
                    $pais !== '' ? $pais : "el pais {$paisId}",
                    $fecha,
                ),
            );
        }

        if ($filas->count() > 1) {
            return new self(
                self::EMPATE,
                null,
                $filas->all(),
                sprintf(
                    'Hay %d sociedades cubriendo %s al %s (%s). No elijo yo: un empate aqui es '
                    .'una factura emitida por la sociedad equivocada. Revise los periodos de '
                    .'cobertura, que desde 3.10 no deberian poder solaparse.',
                    $filas->count(),
                    $pais,
                    $fecha,
                    $filas->pluck('code')->implode(', '),
                ),
            );
        }

        $entidad = $filas->first();

        return new self(
            self::HAY,
            $entidad,
            [$entidad],
            sprintf(
                '%s factura a %s desde el %s (%s).',
                $entidad->code,
                $pais,
                $entidad->valid_from,
                $entidad->coverage_basis,
            ),
        );
    }
}
