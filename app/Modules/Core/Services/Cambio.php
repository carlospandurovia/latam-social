<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;

/**
 * Convertir dinero de una moneda a otra, y poder explicar cómo (iteración 9.1).
 *
 * Hasta hoy `exchange_rates` estaba en el esquema y **nadie la leía**. El
 * buscador de creadores lo dice por escrito desde `7.4`: no convierte porque
 * *«convertir con una tabla vacía o vieja daría un número con pinta de
 * presupuesto y sin nada detrás»*. Esta clase es lo que faltaba, y hereda esa
 * exigencia: **nunca devuelve un número a secas**.
 *
 * ### Lo que `BR-FIN-004` obliga a devolver
 *
 * *«Toda conversión registra monto origen, moneda origen, monto destino, moneda
 * destino, tasa, fecha de la tasa y fuente.»* Por eso `convertir()` devuelve un
 * objeto y no un `float`: quien llama tiene que poder guardar las siete cosas, y
 * un `float` deja seis de ellas a que alguien se acuerde.
 *
 * ### La fecha de la tasa NO es la fecha de la operación
 *
 * Es la parte que se equivoca sola. `BR-FIN-009` dice que se aplica la tasa
 * **vigente en la fecha de la operación**, y hay días sin tasa publicada:
 * sábados, domingos y feriados. La decisión (2026-08-27) es usar **la última
 * anterior, diciéndolo**: se convierte con la tasa del viernes y lo que se
 * guarda como `fecha` es **la del viernes**, no la del domingo. Guardar la de la
 * operación sería más cómodo de leer y haría que el histórico afirmara que el
 * domingo hubo una tasa que nunca existió.
 *
 * ### Y hay un límite, porque «la última anterior» tapa una avería
 *
 * Un fin de semana son dos días. Si la última tasa es de hace tres semanas, lo
 * que pasa no es que fuera feriado: es que **el cron dejó de traerlas** y nadie
 * se enteró. `DIAS_ATRAS` corta ahí. Sin ese corte, una tabla congelada se ve
 * exactamente igual que una sana, que es la forma que tienen estas cosas de
 * descubrirse el día de la liquidación.
 *
 * ### Qué lado se aplica lo decide quien llama
 *
 * SUNAT publica compra y venta, y cuál corresponde a cada operación es una regla
 * contable, no técnica. No hay valor por defecto a propósito: `Q-63`.
 */
final class Cambio
{
    public const HAY = 'hay';

    /** Nadie ha dicho qué fuente manda para ese par en esa fecha. */
    public const SIN_FUENTE = 'sin_fuente';

    /** Hay fuente, pero no ha publicado nada aplicable a esa fecha. */
    public const SIN_TASA = 'sin_tasa';

    /** La última tasa es demasiado vieja para ser un fin de semana. */
    public const RANCIA = 'rancia';

    public const COMPRA = 'buy';

    public const VENTA = 'sell';

    public const MEDIO = 'mid';

    /**
     * Cuántos días atrás se acepta buscar una tasa anterior.
     *
     * Diez: cubre un fin de semana largo y una semana de fiestas patrias, y no
     * cubre un cron parado. El número está aquí y no en la configuración porque
     * cambiarlo es una decisión, no un ajuste.
     */
    public const DIAS_ATRAS = 10;

    /** @var array<string, string> */
    public const LADOS = [
        self::COMPRA => 'Compra',
        self::VENTA => 'Venta',
        self::MEDIO => 'Medio',
    ];

    /**
     * @param string|null $fuente Código de `fx_sources`, cuando la hay.
     * @param string|null $fecha La fecha REAL de la tasa, que puede ser anterior a la pedida.
     */
    private function __construct(
        public readonly string $resultado,
        public readonly ?string $tasa,
        public readonly ?string $fecha,
        public readonly ?string $fuente,
        public readonly string $lado,
        public readonly string $explicacion,
    ) {}

    public function hay(): bool
    {
        return $this->resultado === self::HAY;
    }

    /**
     * La tasa aplicable para convertir `$base` a `$quote` en `$fecha`.
     *
     * Devuelve el veredicto entero —no sólo el número— porque quien pregunta
     * necesita poder explicar el «no» tanto como usar el «sí». Es el mismo
     * criterio que `CoberturaFacturacion`, y por el mismo motivo: descubrir en
     * la pantalla de pagos que no hay tasa es tarde, pero descubrirlo con un
     * `null` sin explicación es peor.
     */
    public static function tasa(string $base, string $quote, string $fecha, string $lado): self
    {
        $base = mb_strtoupper($base);
        $quote = mb_strtoupper($quote);

        if ($base === $quote) {
            return new self(self::HAY, '1.00000000', $fecha, null, $lado,
                "{$base} y {$quote} son la misma moneda: no se convierte nada.");
        }

        $oficial = self::fuenteOficial($base, $quote, $fecha);

        if ($oficial === null) {
            return new self(self::SIN_FUENTE, null, null, null, $lado, sprintf(
                'Nadie ha dicho que fuente publica el tipo de cambio de %s a %s al %s. Hay que '
                .'declararla en Tipos de cambio, diciendo desde cuando, antes de poder convertir '
                .'importes de ese par.',
                $base, $quote, $fecha,
            ));
        }

        $fila = DB::table('exchange_rates')
            ->where('base_currency_code', $base)
            ->where('quote_currency_code', $quote)
            ->where('source', $oficial->source_code)
            ->where('side', $lado)
            ->whereDate('rate_date', '<=', $fecha)
            ->orderByDesc('rate_date')
            ->first(['rate', 'rate_date', 'source']);

        if ($fila === null) {
            return new self(self::SIN_TASA, null, null, $oficial->source_code, $lado, sprintf(
                '%s no ha publicado ningun tipo de cambio de %s a %s (%s) hasta el %s.',
                $oficial->source_code, $base, $quote, self::LADOS[$lado] ?? $lado, $fecha,
            ));
        }

        $dias = self::diasEntre((string) $fila->rate_date, $fecha);

        if ($dias > self::DIAS_ATRAS) {
            return new self(self::RANCIA, null, (string) $fila->rate_date, $oficial->source_code, $lado, sprintf(
                'La ultima tasa de %s a %s que publico %s es del %s: %d dias antes del %s. Eso ya '
                .'no es un feriado, es que dejaron de llegar. No se convierte con ella.',
                $base, $quote, $oficial->source_code, $fila->rate_date, $dias, $fecha,
            ));
        }

        return new self(self::HAY, (string) $fila->rate, (string) $fila->rate_date, (string) $fila->source, $lado,
            $dias === 0
                ? sprintf('%s a %s al %s (%s), segun %s.', $base, $quote, $fila->rate_date,
                    self::LADOS[$lado] ?? $lado, $fila->source)
                : sprintf('%s a %s con la tasa del %s (%s, %s), que es la ultima publicada antes del %s.',
                    $base, $quote, $fila->rate_date, self::LADOS[$lado] ?? $lado, $fila->source, $fecha),
        );
    }

    /**
     * Convierte un importe y devuelve **las siete cosas** de `BR-FIN-004`.
     *
     * El importe viaja como cadena y **la multiplicación la hace el motor**, con
     * `DECIMAL`. `BR-FIN-004` prohíbe el punto flotante para dinero, y en PHP sin
     * `bcmath` no queda aritmética exacta: `float` pierde, y multiplicar enteros
     * escalados a mano se desborda —18 dígitos de importe por 18 de tasa son 36,
     * y un `int` de PHP tiene 19—. El motor ya guarda estos importes en
     * `DECIMAL(18,4)`, así que multiplicar ahí no añade una dependencia y da el
     * mismo número que dará la columna. **No se usa `bcmath` a propósito**: no
     * está en todos los hostings compartidos, y descubrirlo en producción sería
     * descubrirlo convirtiendo dinero.
     *
     * @return array{
     *     tasa: self,
     *     monto_origen: string, moneda_origen: string,
     *     monto_destino: string|null, moneda_destino: string,
     *     tasa_valor: string|null, tasa_fecha: string|null, tasa_fuente: string|null, tasa_lado: string
     * }
     */
    public static function convertir(
        string $monto,
        string $base,
        string $quote,
        string $fecha,
        string $lado,
    ): array {
        $base = mb_strtoupper($base);
        $quote = mb_strtoupper($quote);

        $tasa = self::tasa($base, $quote, $fecha, $lado);

        // Los decimales son de la moneda DESTINO y no de la pantalla: es lo que
        // dice el comentario de `currencies.decimal_places` desde la Fase 2.
        $decimales = (int) (DB::table('currencies')->where('code', $quote)->value('decimal_places') ?? 2);

        return [
            'tasa' => $tasa,
            'monto_origen' => $monto,
            'moneda_origen' => $base,
            'monto_destino' => $tasa->hay()
                ? self::multiplicar($monto, (string) $tasa->tasa, $decimales)
                : null,
            'moneda_destino' => $quote,
            'tasa_valor' => $tasa->tasa,
            'tasa_fecha' => $tasa->fecha,
            'tasa_fuente' => $tasa->fuente,
            'tasa_lado' => $lado,
        ];
    }

    /**
     * La fuente que manda para un par en una fecha.
     *
     * Consulta sin desempatar, igual que `Cobertura::quienCubre()`: de que no
     * haya empate responde `fos_sin_solape`, y si alguna vez lo hubiera se
     * vería como dos filas, no como una elegida a dedo.
     */
    public static function fuenteOficial(string $base, string $quote, string $fecha): ?object
    {
        return DB::table('fx_official_sources as fos')
            ->join('fx_sources as s', 's.code', '=', 'fos.source_code')
            ->where('fos.base_currency_code', mb_strtoupper($base))
            ->where('fos.quote_currency_code', mb_strtoupper($quote))
            ->whereDate('fos.valid_from', '<=', $fecha)
            ->where(function ($q) use ($fecha): void {
                $q->whereNull('fos.valid_to')->orWhereDate('fos.valid_to', '>=', $fecha);
            })
            ->where('s.is_active', 1)
            ->first(['fos.source_code', 'fos.valid_from', 'fos.valid_to', 's.name']);
    }

    /**
     * Declara qué fuente manda para un par, cerrando la anterior el día antes.
     *
     * El orden —cerrar y luego abrir— no es preferencia: `uq_fos_current` sólo
     * admite una abierta por par, así que al revés la base lo rechaza. Es la
     * misma secuencia que `Cobertura::abrir()`, y va dentro de la transacción de
     * quien llama por la misma razón.
     */
    public static function declararOficial(string $base, string $quote, string $fuente, string $desde): int
    {
        $base = mb_strtoupper($base);
        $quote = mb_strtoupper($quote);

        $abierta = DB::table('fx_official_sources')
            ->where('base_currency_code', $base)->where('quote_currency_code', $quote)
            ->whereNull('valid_to')
            ->first(['id']);

        if ($abierta !== null) {
            DB::table('fx_official_sources')->where('id', $abierta->id)->update([
                'valid_to' => date('Y-m-d', strtotime($desde.' -1 day')),
                'updated_at' => now(),
            ]);
        }

        return (int) DB::table('fx_official_sources')->insertGetId([
            'base_currency_code' => $base,
            'quote_currency_code' => $quote,
            'source_code' => $fuente,
            'valid_from' => $desde,
            'valid_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Guarda una tasa publicada. **No pisa** la que ya hubiera.
     *
     * `insertOrIgnore` y no `updateOrInsert`: `tg_fx_inmutable` rechazaría el
     * `UPDATE` con un `45000`, así que un `updateOrInsert` convertiría «esta
     * tasa ya la teníamos» —que es lo normal cuando el cron repite un día— en
     * una excepción. Devuelve si la fila es nueva, para que quien llama pueda
     * decir cuántas trajo de verdad.
     */
    public static function anotar(
        string $base,
        string $quote,
        string $fecha,
        string $tasa,
        string $fuente,
        string $lado,
    ): bool {
        return DB::table('exchange_rates')->insertOrIgnore([
            'base_currency_code' => mb_strtoupper($base),
            'quote_currency_code' => mb_strtoupper($quote),
            'rate_date' => $fecha,
            'rate' => $tasa,
            'side' => $lado,
            'source' => $fuente,
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    private static function diasEntre(string $desde, string $hasta): int
    {
        return (int) round((strtotime($hasta) - strtotime($desde)) / 86400);
    }

    /**
     * `monto x tasa`, redondeado a los decimales de la moneda destino.
     *
     * La cuenta la hace el motor con `DECIMAL`. El `CAST` intermedio a 12
     * decimales no es decorativo: sin él, `DECIMAL(18,4) * DECIMAL(18,8)` se
     * redondea a la escala del primer operando antes de que nadie mire, y
     * convertir 100,00 a una moneda con seis decimales daría un número corto.
     */
    private static function multiplicar(string $monto, string $tasa, int $decimales): string
    {
        /** @var object{resultado: string} $fila */
        $fila = DB::selectOne(
            'SELECT CAST(CAST(? AS DECIMAL(28,12)) * CAST(? AS DECIMAL(28,12)) AS DECIMAL(28,'.$decimales.')) '
            .'AS resultado',
            [$monto, $tasa],
        );

        return (string) $fila->resultado;
    }
}
