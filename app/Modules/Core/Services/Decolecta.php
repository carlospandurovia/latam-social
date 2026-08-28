<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * El tipo de cambio de SUNAT, a través de Decolecta (9.2).
 *
 * `GET {base}/v1/tipo-cambio/sunat?date=YYYY-MM-DD`, con `Authorization: Bearer`,
 * y devuelve `buy_price`, `sell_price`, `base_currency`, `quote_currency`, `date`.
 *
 * ### Lo que hay que saber antes de usarla
 *
 * **Decolecta publica el tipo de cambio de SUNAT, y SUNAT sólo publica
 * `USD → PEN`.** Ahí no hay `COP→PEN` ni `MXN→PEN`. Para los demás pares hará
 * falta otra fuente o carga manual, y `fx_official_sources` es donde eso se dice
 * par por par —en vez de descubrirse el día que haya que pagar a un creador
 * mexicano—. Por eso esta clase **no acepta un par como parámetro**: sabe traer
 * lo único que su fuente publica, y decirlo es más honesto que aceptar `COP` y
 * fallar raro.
 *
 * ### Cada final tiene su nombre
 *
 * `traer()` no lanza. Devuelve el resultado con su `outcome`, que es el mismo
 * juego de valores que guarda `fx_fetch_runs`, porque «no hay credencial», «la
 * API contestó 500» y «contestó 200 con un cuerpo que no entiendo» exigen tres
 * arreglos distintos y en un `catch` genérico se ven iguales.
 *
 * **Nada de lo que sale de aquí lleva la credencial**, ni el cuerpo crudo de la
 * respuesta: `detail` es texto nuestro. Un log que copia respuestas es un log
 * que un día copia una cabecera.
 */
final class Decolecta
{
    public const FUENTE = 'sunat';

    public const URL = 'https://api.decolecta.com';

    public const OK = 'ok';

    public const SIN_CREDENCIAL = 'sin_credencial';

    public const ERROR_HTTP = 'error_http';

    public const ERROR_RED = 'error_red';

    public const RESPUESTA_RARA = 'respuesta_rara';

    /** Lo que publica: sólo este par, y las dos puntas. */
    public const BASE = 'USD';

    public const CONTRA = 'PEN';

    private const SEGUNDOS = 15;

    /**
     * Trae el tipo de cambio de un día y lo anota. Idempotente.
     *
     * Repetir un día no revienta y no duplica: `Cambio::anotar()` es
     * `insertOrIgnore` y `uq_fx_rate` es quien lo garantiza. El contador cuenta
     * las que entraron DE VERDAD, para que «trajo 0» y «trajo 2» se distingan
     * en el registro.
     *
     * @return array{outcome: string, nuevas: int, http: ?int, detalle: string}
     */
    public static function traer(string $fecha): array
    {
        $clave = CredencialFuente::clave(self::FUENTE);

        if ($clave === null) {
            return self::fin(self::SIN_CREDENCIAL, 0, null,
                'No hay credencial de Decolecta: ni en el entorno ni guardada en la pantalla.');
        }

        $base = (string) (DB::table('fx_sources')->where('code', self::FUENTE)->value('api_base_url')
            ?: config('latam.cambio.decolecta.url', self::URL));

        try {
            $respuesta = Http::withToken($clave)
                ->acceptJson()
                ->timeout(self::SEGUNDOS)
                ->get(rtrim($base, '/').'/v1/tipo-cambio/sunat', ['date' => $fecha]);
        } catch (Throwable $e) {
            // El mensaje de la excepcion SI puede llevar la URL, y la URL no
            // lleva la clave --va en cabecera--. Aun asi se recorta: `detail`
            // son 255 y una traza larga ahi no la lee nadie.
            return self::fin(self::ERROR_RED, 0, null,
                'No se pudo hablar con Decolecta: '.mb_substr($e->getMessage(), 0, 160));
        }

        if (!$respuesta->successful()) {
            return self::fin(self::ERROR_HTTP, 0, $respuesta->status(), match (true) {
                $respuesta->status() === 401 || $respuesta->status() === 403 => 'Decolecta rechazo la credencial. Hay que volver a configurarla.',
                $respuesta->status() === 404 => "Decolecta no tiene tipo de cambio para el {$fecha}.",
                $respuesta->status() === 429 => 'Decolecta esta limitando las peticiones. Se reintenta en la proxima corrida.',
                default => "Decolecta contesto {$respuesta->status()}.",
            });
        }

        /** @var mixed $cuerpo */
        $cuerpo = $respuesta->json();

        // La API contesta un objeto para `date` y una lista para `month`. Se
        // admiten los dos y se coge el primero: pedir por dia y recibir lista
        // es raro, pero fallar por eso seria fallar por la forma y no por el
        // contenido.
        if (is_array($cuerpo) && array_is_list($cuerpo)) {
            $cuerpo = $cuerpo[0] ?? null;
        }

        if (!is_array($cuerpo) || !isset($cuerpo['buy_price'], $cuerpo['sell_price'])) {
            return self::fin(self::RESPUESTA_RARA, 0, $respuesta->status(),
                'Decolecta contesto 200 pero sin buy_price y sell_price. No se anota nada.');
        }

        $dia = is_string($cuerpo['date'] ?? null) ? substr((string) $cuerpo['date'], 0, 10) : $fecha;

        // Se validan LAS DOS antes de anotar NINGUNA. Escribiendo sobre la
        // marcha, un `sell_price` malo dejaba la compra ya anotada y el
        // resultado diciendo `RESPUESTA_RARA` --y `ck_ffr_nuevas` obliga a que
        // una corrida fallida diga cero--, o sea una fila en `exchange_rates`
        // que el registro jura que no existe. Y `exchange_rates` no se puede
        // corregir despues: `tg_fx_inmutable`.
        $lados = [Cambio::COMPRA => 'buy_price', Cambio::VENTA => 'sell_price'];

        foreach ($lados as $campo) {
            if (!is_numeric($cuerpo[$campo]) || (float) $cuerpo[$campo] <= 0) {
                return self::fin(self::RESPUESTA_RARA, 0, $respuesta->status(),
                    "Decolecta contesto un {$campo} que no es un numero mayor que cero. No se anota nada.");
            }
        }

        $nuevas = 0;
        foreach ($lados as $lado => $campo) {
            // Como cadena hasta el final: `BR-FIN-004` prohibe el punto
            // flotante para dinero, y un `(float)` aqui es la primera vez que
            // el numero pasa por uno.
            if (Cambio::anotar(self::BASE, self::CONTRA, $dia, (string) $cuerpo[$campo], self::FUENTE, $lado)) {
                $nuevas++;
            }
        }

        return self::fin(self::OK, $nuevas, $respuesta->status(), $nuevas === 0
            ? "El {$dia} ya lo teniamos: nada nuevo."
            : "Anotadas {$nuevas} tasas del {$dia}.");
    }

    /**
     * @return array{outcome: string, nuevas: int, http: ?int, detalle: string}
     */
    private static function fin(string $outcome, int $nuevas, ?int $http, string $detalle): array
    {
        return ['outcome' => $outcome, 'nuevas' => $nuevas, 'http' => $http,
            'detalle' => mb_substr($detalle, 0, 255)];
    }
}
