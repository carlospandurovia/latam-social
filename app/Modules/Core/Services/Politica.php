<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Database\Vigencia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La política de precios: la retención y el umbral de rentabilidad (9.18).
 *
 * ### Los tres números
 *
 * - **`withholding_rate`** — la retención que se le aplica al creador que no
 *   emite comprobante (`Q-13`). Cambia por decreto, así que no puede estar en el
 *   código.
 * - **`min_margin_pct`** — el umbral de rentabilidad aceptable (`Q-40`). Es un
 *   juicio comercial que se ajusta con datos reales.
 * - **`margin_basis`** — sobre qué se calcula ese umbral. Y esto **es
 *   configuración, no una pregunta**: con 100 de neto y 29,5 %, el costo es
 *   141,84; el ejemplo del negocio dio 170,21, que es `141,84 × 1,20` —recargo
 *   **sobre el costo**—. Un 20 % de margen **sobre el ingreso** habría dado
 *   177,30. Se siembra en `cost` porque es lo que dice el ejemplo, y la pantalla
 *   enseña las dos cifras para que el cambio se vea antes de guardarlo.
 *
 * ### La cuenta se hace en la base, no en PHP
 *
 * `bruto = neto / (1 - tasa/100)` con `float` da 141.84397163120568…, y de ahí
 * a un `DECIMAL(18,4)` hay un redondeo que puede caer del lado que
 * `ck_ccr_neto_cuadra` no admite. El contenedor **no tiene `bcmath`**, así que la
 * aritmética exacta se hace donde sí la hay: `CAST(... AS DECIMAL(28,12))` en el
 * motor. Es la misma técnica de `9.1` y de `9.3`, por el mismo motivo.
 *
 * ### Nunca bloquea
 *
 * Sin política sembrada, `datos()` devuelve ceros y el panel de configuración lo
 * dice en rojo. Una retención de 0 es una cuenta que no retiene nada, no un
 * error: se puede operar mientras alguien pone el número (`DEC-190`).
 */
final class Politica
{
    public const COSTO = 'cost';

    public const INGRESO = 'revenue';

    /** @var array<string, string> */
    public const BASES = [
        self::COSTO => 'Recargo sobre el costo — el costo por (1 + umbral)',
        self::INGRESO => 'Margen sobre el ingreso — el costo entre (1 − umbral)',
    ];

    /** La política vigente, o `null` si todavía no hay ninguna. */
    public static function vigente(): ?object
    {
        if (!Schema::hasTable('pricing_policies')) {
            return null;
        }

        return DB::table('pricing_policies')->whereNull('valid_to')->first();
    }

    /**
     * Los tres números, siempre completos.
     *
     * @return array{tasa: float, umbral: float, base: string, nota: ?string, configurada: bool}
     */
    public static function datos(): array
    {
        $fila = self::vigente();

        return [
            'tasa' => (float) ($fila->withholding_rate ?? 0),
            'umbral' => (float) ($fila->min_margin_pct ?? 0),
            'base' => self::base($fila->margin_basis ?? null),
            'nota' => $fila->note ?? null,
            'configurada' => $fila !== null,
        ];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public static function versiones(): Collection
    {
        return DB::table('pricing_policies as pp')
            ->leftJoin('users as u', 'u.id', '=', 'pp.created_by_user_id')
            ->orderByDesc('pp.valid_from')
            ->orderByDesc('pp.id')
            ->get(['pp.id', 'pp.uuid', 'pp.withholding_rate', 'pp.min_margin_pct',
                'pp.margin_basis', 'pp.note', 'pp.valid_from', 'pp.valid_to',
                'u.name as autor']);
    }

    // ------------------------------------------------------------- la cuenta

    /**
     * Lo que cuesta pagarle `$neto` a alguien con una retención de `$tasa` %.
     *
     * `100 / (1 − 0,295) = 141,8440`. Es lo que la campaña provisiona; el
     * creador ve 100.
     */
    public static function brutoDesdeNeto(float $neto, ?float $tasa = null): float
    {
        $tasa ??= self::datos()['tasa'];

        if ($tasa >= 100.0 || $tasa < 0.0) {
            // `ck_pp_tasa` lo impide en la base; aqui se para antes de dividir
            // por cero, porque este metodo tambien recibe tasas de una
            // participacion vieja o de un formulario.
            throw new RuntimeException(
                'Una retencion del 100 % o mas dejaria el costo en infinito: no hay bruto que '
                .'deje ese neto despues de retener.',
            );
        }

        return self::exacto('? / (1 - ? / 100)', [$neto, $tasa]);
    }

    /** Lo que recibe el creador si el costo es `$bruto`. */
    public static function netoDesdeBruto(float $bruto, ?float $tasa = null): float
    {
        $tasa ??= self::datos()['tasa'];

        return self::exacto('? * (100 - ?) / 100', [$bruto, $tasa]);
    }

    /**
     * El ingreso más bajo con el que esta participación llega al umbral.
     *
     * Con base `cost` es `costo × (1 + umbral/100)` —lo que el negocio llamó
     * «el ingreso aceptable más bajo»—. Con base `revenue` es
     * `costo / (1 − umbral/100)`, que para el mismo 20 % da más: el margen sobre
     * el ingreso es más exigente que el recargo sobre el costo, y ésa es
     * justamente la decisión que la pantalla deja ver.
     */
    public static function ingresoMinimo(float $costo, ?float $umbral = null, ?string $base = null): float
    {
        $datos = self::datos();
        $umbral ??= $datos['umbral'];
        $base = self::base($base ?? $datos['base']);

        if ($base === self::INGRESO) {
            if ($umbral >= 100.0) {
                throw new RuntimeException(
                    'Un margen del 100 % sobre el ingreso pide un ingreso infinito.',
                );
            }

            return self::exacto('? / (1 - ? / 100)', [$costo, $umbral]);
        }

        return self::exacto('? * (1 + ? / 100)', [$costo, $umbral]);
    }

    /**
     * Las tres cifras del ejemplo del negocio, juntas.
     *
     * Es lo que la pantalla enseña mientras se teclea: *«te pagaré 100, me
     * cuesta 141,84, y para que salga a cuenta el ingreso tendría que llegar a
     * 170,21»*.
     *
     * @return array{neto: float, tasa: float, retenido: float, costo: float,
     *               umbral: float, base: string, minimo: float}
     */
    public static function desglose(float $neto, ?float $tasa = null, ?float $umbral = null, ?string $base = null): array
    {
        $datos = self::datos();
        $tasa ??= $datos['tasa'];
        $costo = self::brutoDesdeNeto($neto, $tasa);

        return [
            'neto' => $neto,
            'tasa' => $tasa,
            'retenido' => round($costo - $neto, 4),
            'costo' => $costo,
            'umbral' => $umbral ?? $datos['umbral'],
            'base' => self::base($base ?? $datos['base']),
            'minimo' => self::ingresoMinimo($costo, $umbral, $base),
        ];
    }

    // ------------------------------------------------------------- publicar

    /**
     * Publica una política nueva, cerrando la anterior **el día antes**.
     *
     * El orden —cerrar y luego abrir— lo impone `uq_pp_current`, igual que la
     * cobertura de `4.5` y los términos de `9.16`.
     *
     * @param array<string, mixed> $datos
     */
    public static function publicar(array $datos, string $desde, ?int $usuarioId): string
    {
        $vigente = self::vigente();

        if ($vigente !== null && !Vigencia::puedeRelevar($desde, (string) $vigente->valid_from)) {
            throw new RuntimeException(sprintf(
                'La politica nueva empezaria el %s y la vigente empezo el %s. La nueva tiene que '
                .'empezar despues: si no, habria dos politicas el mismo dia y de ahi sale la tasa '
                .'con la que se pacta.',
                $desde, $vigente->valid_from,
            ));
        }

        $uuid = (string) Str::uuid();

        DB::transaction(function () use ($datos, $desde, $usuarioId, $vigente, $uuid): void {
            if ($vigente !== null) {
                DB::table('pricing_policies')->where('id', $vigente->id)->update([
                    'valid_to' => Vigencia::cerrarElDiaAntesDe($desde),
                    'updated_at' => now(),
                ]);
            }

            DB::table('pricing_policies')->insert([
                'uuid' => $uuid,
                'withholding_rate' => $datos['withholding_rate'],
                'min_margin_pct' => $datos['min_margin_pct'],
                'margin_basis' => $datos['margin_basis'],
                'note' => $datos['note'] ?? null,
                'valid_from' => $desde,
                'created_by_user_id' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'pricing_policy.published',
            tipoEntidad: 'pricing_policy',
            idEntidad: (int) DB::table('pricing_policies')->where('uuid', $uuid)->value('id'),
            cambios: [
                'retencion' => ['antes' => $vigente?->withholding_rate, 'despues' => $datos['withholding_rate']],
                'umbral' => ['antes' => $vigente?->min_margin_pct, 'despues' => $datos['min_margin_pct']],
                'base' => ['antes' => $vigente?->margin_basis, 'despues' => $datos['margin_basis']],
            ],
        );

        return $uuid;
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        $fila = self::vigente();

        if ($fila === null) {
            return [Aviso::rojo(
                'No hay política de precios. Mientras no la haya, pactar el neto de un creador '
                .'no retiene nada y ninguna participación se compara con un umbral.',
            )];
        }

        $avisos = [];

        if ((float) $fila->withholding_rate <= 0) {
            $avisos[] = Aviso::rojo(
                'La retención está en 0 %: pactar 100 con un creador costará 100, y si luego hay '
                .'que retener, la diferencia sale del margen.',
            );
        }

        if ((float) $fila->min_margin_pct <= 0) {
            $avisos[] = Aviso::ambar(
                'El umbral de rentabilidad está en 0 %: ninguna participación se marcará nunca '
                .'como poco rentable.',
            );
        }

        if (trim((string) ($fila->note ?? '')) === '') {
            $avisos[] = Aviso::ambar(
                'La política vigente no dice por qué son esos números. Un umbral sin explicación '
                .'es un número que nadie se atreve a cambiar dentro de un año.',
            );
        }

        return $avisos;
    }

    // ------------------------------------------------------------------ apoyo

    private static function base(?string $base): string
    {
        return $base === self::INGRESO ? self::INGRESO : self::COSTO;
    }

    /**
     * La cuenta, hecha por el motor y no por PHP.
     *
     * El contenedor no tiene `bcmath` y `float` redondea por donde no debe: el
     * resultado va a un `DECIMAL(18,4)` que `ck_ccr_neto_cuadra` vuelve a
     * comprobar. Misma técnica que `9.1` y `9.3`.
     *
     * @param list<float> $valores
     */
    private static function exacto(string $formula, array $valores): float
    {
        $fila = DB::selectOne(
            'SELECT ROUND(CAST('.$formula.' AS DECIMAL(28,12)), 4) AS r',
            $valores,
        );

        return (float) $fila->r;
    }
}
