<?php

declare(strict_types=1);

namespace App\Modules\Creator\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Los chequeos de coherencia de `BR-CREATOR-004`.
 *
 * La regla dice, literalmente: *«Los resultados anómalos se marcan para revisión
 * humana, nunca se rechazan automáticamente.»* Esta clase **no rechaza nada**.
 * Devuelve un estado y los motivos, y quien la llama los guarda.
 *
 * Existía desde la iteración 2.6 la columna donde anotarlo y **ni una sola línea
 * que la escribiera**. Como el valor por defecto era `0` —«no es anómalo»—, cada
 * métrica insertada afirmaba haber pasado unos chequeos que nunca se ejecutaron.
 * Eso se corrigió en `H-06`: ahora el estado de partida es `pending_review`, y
 * esta clase es lo que lo mueve de ahí.
 *
 * **Los umbrales son juicio de negocio, no verdad técnica.** Viven en
 * `config('latam.redes')` y están abiertos a revisión (`DEC-063`): un 3 % de
 * engagement es excelente en una cuenta de un millón de seguidores y mediocre en
 * una de mil. Por eso el resultado es «mírelo una persona», no «esto es falso».
 */
final class CoherenciaMetrica
{
    public const PENDIENTE = 'pending_review';

    public const LIMPIA = 'clean';

    public const ANOMALA = 'anomalous';

    /**
     * @param array{followers?: int|null, engagement_rate?: float|null, captured_at?: string|null} $metrica
     * @return array{estado: string, motivos: list<string>}
     */
    public static function evaluar(int $cuentaId, array $metrica): array
    {
        // La fecha de la captura, no la de hoy. Importa cuando se registra una
        // metrica con fecha pasada: la ventana se mide ENTRE CAPTURAS.
        $capturadaEn = CarbonImmutable::parse($metrica['captured_at'] ?? 'now');

        $motivos = self::engagementFueraDeRango($metrica['engagement_rate'] ?? null);
        $motivos = array_merge($motivos, self::saltoDeSeguidores($cuentaId, $metrica['followers'] ?? null, $capturadaEn));

        return [
            'estado' => $motivos === [] ? self::LIMPIA : self::ANOMALA,
            'motivos' => $motivos,
        ];
    }

    /**
     * Une los motivos en una línea para `anomaly_note`, que es `VARCHAR(255)`.
     *
     * @param list<string> $motivos
     */
    public static function nota(array $motivos): ?string
    {
        if ($motivos === []) {
            return null;
        }

        return mb_substr(implode(' · ', $motivos), 0, 255);
    }

    /**
     * Un engagement por debajo del suelo sugiere seguidores comprados; muy por
     * encima del techo sugiere que el número no sale de donde dice.
     *
     * @return list<string>
     */
    private static function engagementFueraDeRango(?float $tasa): array
    {
        if ($tasa === null) {
            return [];
        }

        $suelo = (float) config('latam.redes.engagement_min', 0.1);
        $techo = (float) config('latam.redes.engagement_max', 20.0);

        if ($tasa < $suelo) {
            return ["Engagement del {$tasa} %, por debajo del mínimo plausible ({$suelo} %)"];
        }

        if ($tasa > $techo) {
            return ["Engagement del {$tasa} %, por encima del máximo plausible ({$techo} %)"];
        }

        return [];
    }

    /**
     * Se compara contra el ÚLTIMO snapshot, y se mira la variación en ambos
     * sentidos: una subida brusca sugiere seguidores comprados y una bajada
     * brusca sugiere que la plataforma acaba de purgarlos. Las dos cosas
     * interesan a quien va a pagar por esa audiencia.
     *
     * @return list<string>
     */
    private static function saltoDeSeguidores(int $cuentaId, ?int $seguidores, CarbonImmutable $capturadaEn): array
    {
        if ($seguidores === null) {
            return [];
        }

        // El anterior es el ultimo ANTES de esta captura, no el ultimo a secas:
        // si se importa una metrica vieja, compararla con una posterior daria un
        // salto inventado y del signo contrario.
        $anterior = DB::table('social_account_snapshots')
            ->where('social_account_id', $cuentaId)
            ->whereNotNull('followers')
            ->where('captured_at', '<=', $capturadaEn->format('Y-m-d H:i:s.v'))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first(['followers', 'captured_at']);

        // Sin histórico no hay salto que medir. No es «limpio» por eso: es que
        // esta comprobación concreta no aplica todavía.
        if ($anterior === null || (int) $anterior->followers === 0) {
            return [];
        }

        $dias = (int) config('latam.redes.ventana_dias', 30);
        $limite = (float) config('latam.redes.salto_max_pct', 50.0);

        $transcurridos = (int) CarbonImmutable::parse((string) $anterior->captured_at)->diffInDays($capturadaEn);

        if ($transcurridos > $dias) {
            return [];
        }

        $previo = (int) $anterior->followers;
        $variacion = (($seguidores - $previo) / $previo) * 100;

        if (abs($variacion) <= $limite) {
            return [];
        }

        $signo = $variacion > 0 ? 'subida' : 'caída';
        $pct = number_format(abs($variacion), 1);

        return ["Seguidores: {$signo} del {$pct} % en {$transcurridos} día(s) ({$previo} → {$seguidores})"];
    }
}
