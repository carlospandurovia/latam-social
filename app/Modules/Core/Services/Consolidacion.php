<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sumar dinero de varias monedas sin mentir (`D-12`).
 *
 * ### Qué resuelve
 *
 * `D-8` dejó el bloque financiero **por moneda y sin total**, con esta frase en
 * la plantilla: *«sumar soles y dólares en una cifra es la mentira más cara que
 * puede contar un panel»*. Sigue siendo verdad. Lo que cambia aquí no es la
 * regla: es que ahora hay **con qué** convertir y, sobre todo, **cómo decir lo
 * que no se pudo convertir**.
 *
 * ### Las tres reglas de este total
 *
 * 1. **Nunca sale un total de menos en silencio.** Si una moneda no tiene tasa,
 *    su importe NO se cuenta y el total se marca *parcial*, diciendo qué moneda
 *    quedó fuera, cuánto y por qué. Un consolidado a la ligera no sale mal:
 *    sale DE MENOS, y ése es el fallo que nadie ve.
 * 2. **Nunca se inventa un cero.** Sin líneas no hay total: hay un guion.
 * 3. **Siempre dice con qué tasa.** Fecha, lado y —en la pantalla— fuente.
 *
 * ### Ingresos y egresos no llevan el mismo lado
 *
 * SUNAT publica compra y venta, y la regla contable peruana usa compra para lo
 * que entra y venta para lo que sale. Por eso son dos ajustes: consolidar lo
 * facturado y lo pagado a creadores con la misma tasa sería elegir a cuál de los
 * dos números mentirle. `Cambio` no tiene lado por defecto a propósito
 * (`Q-63`); esto lo contesta **para el panel**, y sólo para el panel.
 *
 * ### La aritmética la hace el motor
 *
 * `BR-FIN-004` prohíbe el punto flotante para dinero. La multiplicación ya la
 * hacía `Cambio::convertir()` en `DECIMAL`; la SUMA de lo convertido se hace
 * aquí igual, con una consulta de una línea y placeholders. Sumar cadenas
 * decimales en PHP sería reimplementar `bcmath` --que este proyecto evita a
 * propósito, porque no está en todos los hostings compartidos--.
 *
 * ### Lo que este número NO es
 *
 * No es contabilidad. Convierte un AGREGADO con **una sola** tasa --la del
 * cierre del periodo-- mientras que cada factura y cada pago llevan la suya
 * congelada dentro. Sirve para leer una pantalla y decidir a qué mirar, no para
 * declarar ante nadie (`T-118`).
 */
final class Consolidacion
{
    /** Lo que entra: facturado, cobrado, por cobrar. */
    public const INGRESO = 'ingreso';

    /** Lo que sale: pagado a creadores. */
    public const EGRESO = 'egreso';

    /**
     * Los valores de partida, que sostienen la pantalla si falta la fila.
     *
     * Están aquí **y** sembrados en la migración a propósito: la migración hace
     * que existan de verdad, y esta constante hace que el panel siga hablando
     * aunque alguien borre la fila. Es el mismo par que `Semaforo::PARTIDA`.
     */
    public const PARTIDA = [
        'moneda' => 'PEN',
        'ingresos' => Cambio::COMPRA,
        'egresos' => Cambio::VENTA,
    ];

    public static function fila(): ?object
    {
        if (!Schema::hasTable('consolidation_settings')) {
            return null;
        }

        return DB::table('consolidation_settings')->where('singleton', 1)->first();
    }

    /**
     * Los ajustes vigentes, completos siempre.
     *
     * @return array{moneda: string, ingresos: string, egresos: string, confirmado: bool}
     */
    public static function ajustes(): array
    {
        $fila = self::fila();

        return [
            'moneda' => mb_strtoupper((string) ($fila->base_currency_code ?? self::PARTIDA['moneda'])),
            'ingresos' => (string) ($fila->income_rate_side ?? self::PARTIDA['ingresos']),
            'egresos' => (string) ($fila->expense_rate_side ?? self::PARTIDA['egresos']),
            'confirmado' => ($fila->confirmed_at ?? null) !== null,
        ];
    }

    /**
     * Guarda los tres ajustes y deja constancia de quién los confirmó.
     *
     * Guardar **es** confirmar: quien abre la pantalla y pulsa está diciendo que
     * ésa es la moneda del negocio, y desde ese momento el aviso ámbar deja de
     * salir. Por eso `confirmed_at` se escribe aquí y no en la migración.
     *
     * @param array{base_currency_code: string, income_rate_side: string, expense_rate_side: string} $datos
     */
    public static function guardar(array $datos, ?int $usuarioId): void
    {
        $antes = (array) (self::fila() ?? new \stdClass);

        DB::table('consolidation_settings')->updateOrInsert(
            ['singleton' => 1],
            $datos + [
                'confirmed_at' => now(),
                'updated_by_user_id' => $usuarioId,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        // Cambiar la moneda base cambia TODOS los totales del panel de golpe.
        // Quien lo hizo y cuando tiene que quedar escrito.
        Bitacora::registrar('consolidation_settings.updated', 'consolidation_settings', 1,
            Bitacora::diferencias($antes, $datos));
    }

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        $ajustes = self::ajustes();
        $avisos = [];

        if (!$ajustes['confirmado']) {
            $avisos[] = Aviso::ambar(sprintf(
                'El panel consolida en %s, que es el valor de partida, y nadie lo ha confirmado '
                .'todavía. Los totales ya se calculan y son correctos si %s es la moneda del '
                .'negocio; entra, revísalo y guarda para que este aviso desaparezca.',
                $ajustes['moneda'], $ajustes['moneda'],
            ));
        }

        $moneda = DB::table('currencies')->where('code', $ajustes['moneda'])->first(['is_active', 'name']);

        if ($moneda !== null && (int) $moneda->is_active === 0) {
            $avisos[] = Aviso::ambar(sprintf(
                '%s está apagada en el catálogo de monedas y sigue siendo la moneda de '
                .'consolidación del panel. No rompe nada, pero es una contradicción que conviene '
                .'resolver: o se reactiva, o se consolida en otra.',
                $ajustes['moneda'],
            ));
        }

        foreach (self::sinFuente($ajustes['moneda']) as $codigo) {
            $avisos[] = Aviso::ambar(sprintf(
                'Hay comprobantes en %s y nadie ha declarado qué fuente publica el tipo de cambio '
                .'de %s a %s. Esos importes NO entran en el total consolidado —el panel lo dice—, '
                .'y se arregla declarando la fuente en Tipos de cambio.',
                $codigo, $codigo, $ajustes['moneda'],
            ));
        }

        return $avisos;
    }

    /**
     * Monedas facturadas que hoy no se pueden convertir a la base.
     *
     * Se mira sobre `invoices` porque es donde aparece de verdad una moneda que
     * nadie declaró: las campañas heredan la del cliente y los pagos la de su
     * factura. No pretende ser exhaustivo --lo exhaustivo es el propio panel,
     * que enseña lo que quedó fuera con su motivo-- sino avisar antes.
     *
     * @return list<string>
     */
    private static function sinFuente(string $base): array
    {
        if (!Schema::hasTable('invoices')) {
            return [];
        }

        $codigos = DB::table('invoices')
            ->select('currency_code')
            ->distinct()
            ->limit(20)
            ->pluck('currency_code');

        $hoy = now()->toDateString();
        $faltan = [];

        foreach ($codigos as $codigo) {
            $codigo = mb_strtoupper((string) $codigo);

            if ($codigo === $base) {
                continue;
            }

            if (Cambio::fuenteOficial($codigo, $base, $hoy) === null) {
                $faltan[] = $codigo;
            }
        }

        sort($faltan);

        return $faltan;
    }

    /**
     * Convierte y suma un bloque de líneas por moneda.
     *
     * @param list<array{moneda: string, importe: float}> $lineas
     * @param string $flujo `INGRESO` o `EGRESO`: decide el lado de la tasa.
     * @param string $fecha Con qué día se pide la tasa. `Cambio` puede usar una
     *                      anterior --un domingo no tiene tasa-- y devuelve la
     *                      fecha REAL, que es la que se enseña.
     * @return array{moneda: string, total: ?float, parcial: bool, lado: string,
     *               fecha: ?string, fuera: list<array{moneda: string, importe: float, motivo: string}>}
     */
    public static function consolidar(array $lineas, string $flujo, string $fecha): array
    {
        $ajustes = self::ajustes();
        $base = $ajustes['moneda'];
        $lado = $flujo === self::EGRESO ? $ajustes['egresos'] : $ajustes['ingresos'];

        $montos = [];
        $fuera = [];
        $fechas = [];

        foreach ($lineas as $linea) {
            $codigo = mb_strtoupper((string) $linea['moneda']);

            // El importe entra como CADENA con cuatro decimales: es el mismo
            // numero que el panel ya ensena, y la multiplicacion la hace el
            // motor en `DECIMAL`. Pasar un `float` a `Cambio` seria meter punto
            // flotante justo en el paso que `BR-FIN-004` protege.
            $conversion = Cambio::convertir(
                number_format((float) $linea['importe'], 4, '.', ''),
                $codigo, $base, $fecha, $lado,
            );

            if ($conversion['monto_destino'] === null) {
                $fuera[] = [
                    'moneda' => $codigo,
                    'importe' => (float) $linea['importe'],
                    'motivo' => $conversion['tasa']->explicacion,
                ];

                continue;
            }

            $montos[] = $conversion['monto_destino'];

            if ($codigo !== $base && $conversion['tasa_fecha'] !== null) {
                $fechas[] = (string) $conversion['tasa_fecha'];
            }
        }

        sort($fechas);

        return [
            'moneda' => $base,
            // Sin lineas convertibles NO hay total. Un cero aqui diria «no hay
            // dinero» donde lo que pasa es «no se pudo convertir».
            'total' => $montos === [] ? null : (float) self::sumar($montos),
            'parcial' => $fuera !== [],
            'lado' => $lado,
            // La mas antigua de las usadas: si el total se apoya en una tasa de
            // hace tres dias, eso es lo que hay que enseñar, no la mas nueva.
            'fecha' => $fechas === [] ? null : $fechas[0],
            'fuera' => $fuera,
        ];
    }

    /**
     * La suma, hecha por el motor.
     *
     * La consulta se arma repitiendo un fragmento FIJO --`CAST(? AS DECIMAL)`--
     * tantas veces como importes haya. No se concatena ni un solo valor: los
     * importes viajan como parámetros, que es la regla del proyecto para todo
     * SQL construido (`Semaforo::crudas()` hace lo mismo).
     *
     * @param list<string> $montos
     */
    private static function sumar(array $montos): string
    {
        $piezas = implode(' + ', array_fill(0, count($montos), 'CAST(? AS DECIMAL(18,4))'));

        /** @var object{total: string}|null $fila */
        $fila = DB::selectOne('SELECT ('.$piezas.') AS total', $montos);

        return $fila === null ? '0' : (string) $fila->total;
    }

    /**
     * El catálogo de monedas para el desplegable.
     *
     * @return array<string, string>
     */
    public static function monedas(): array
    {
        $catalogo = [];

        foreach (DB::table('currencies')->where('is_active', 1)->orderBy('code')->get() as $fila) {
            $catalogo[(string) $fila->code] = (string) $fila->name;
        }

        return $catalogo;
    }
}
