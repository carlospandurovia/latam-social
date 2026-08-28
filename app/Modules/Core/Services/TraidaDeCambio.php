<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El registro de lo que el cron trajo, o no trajo (9.2).
 *
 * `Cambio::DIAS_ATRAS` detecta que las tasas dejaron de llegar **cuando alguien
 * va a convertir**, o sea el día de la liquidación. Esto lo enseña antes.
 *
 * Un proceso automático que falla en silencio es un proceso que nadie arregla,
 * porque nadie se entera. Es la misma razón por la que `4.9` guarda cada correo
 * enviado: «¿salió?» tiene que poder contestarse mirando un sitio.
 */
final class TraidaDeCambio
{
    /** Sin noticias en tantos días, algo pasa. Cuadra con `Cambio::DIAS_ATRAS`. */
    public const DIAS_MUDO = 3;

    /**
     * @param array{outcome: string, nuevas: int, http: ?int, detalle: string} $resultado
     */
    public static function anotar(string $fuente, string $fecha, array $resultado): void
    {
        DB::table('fx_fetch_runs')->insert([
            'source_code' => $fuente,
            'requested_date' => $fecha,
            'ran_at' => now(),
            'outcome' => $resultado['outcome'],
            // `ck_ffr_nuevas` no admite otra cosa: un intento fallido no pudo
            // anotar nada, y decir que anoto tres seria contar algo que no paso.
            'rates_new' => $resultado['outcome'] === Decolecta::OK ? $resultado['nuevas'] : 0,
            'http_status' => $resultado['http'],
            'detail' => $resultado['detalle'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return Collection<int, \stdClass> */
    public static function ultimas(string $fuente, int $cuantas = 20): Collection
    {
        return DB::table('fx_fetch_runs')
            ->where('source_code', $fuente)
            ->orderByDesc('ran_at')
            ->limit($cuantas)
            ->get(['requested_date', 'ran_at', 'outcome', 'rates_new', 'http_status', 'detail']);
    }

    /**
     * Qué hay que mirar, dicho con palabras.
     *
     * Devuelve `null` cuando no hay nada que mirar. Es a propósito: una pantalla
     * que siempre tiene un aviso es una pantalla cuyos avisos nadie lee.
     */
    public static function loQueHayQueMirar(string $fuente): ?string
    {
        $ultima = DB::table('fx_fetch_runs')
            ->where('source_code', $fuente)
            ->orderByDesc('ran_at')
            ->first(['ran_at', 'outcome', 'detail']);

        if ($ultima === null) {
            return 'El comando `cambio:traer` no se ha ejecutado nunca. Mientras no corra, '
                .'no entra ninguna tasa nueva.';
        }

        $dias = (int) floor((time() - strtotime((string) $ultima->ran_at)) / 86400);

        if ($dias >= self::DIAS_MUDO) {
            return sprintf(
                'La ultima vez que se intento traer tipos de cambio fue hace %d dias (%s). '
                .'Eso ya no es un fin de semana: revise el cron.',
                $dias, substr((string) $ultima->ran_at, 0, 16),
            );
        }

        if ($ultima->outcome !== Decolecta::OK) {
            return (string) $ultima->detail;
        }

        return null;
    }
}
