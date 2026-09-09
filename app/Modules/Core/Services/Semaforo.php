<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué campañas hay que mirar hoy, y por qué (`D-6`).
 *
 * ### La regla está aquí; los números, en la base
 *
 * `DEC-190` con todas sus letras: *«las reglas deben ser 100 % configurables,
 * este es un sistema flexible parametrizable»*. Lo que esta clase decide es
 * **qué se compara con qué** —la fecha de fin contra hoy, el avance contra un
 * umbral, los creadores aceptados contra el objetivo—. Los tres números con los
 * que se compara salen de `tracking_thresholds` y se cambian desde el admin sin
 * tocar una línea.
 *
 * La prueba de que eso es verdad y no un comentario es
 * `test_cambiar_el_umbral_cambia_el_nivel`: la misma campaña, el mismo día, y
 * el semáforo cambia de color porque cambió la configuración. Si algún día
 * alguien mete un `7` en el código, esa prueba se pone roja.
 *
 * ### Los tres colores
 *
 * | Color | Qué significa |
 * |---|---|
 * | 🔴 **Retrasada** | ya se pasó una fecha comprometida y sigue abierta |
 * | 🟠 **En riesgo** | todavía llega, pero al ritmo de hoy no |
 * | 🟢 **En plazo** | ninguna de las anteriores |
 *
 * Y cada fila lleva **el motivo escrito**. Un semáforo sin motivo es un adorno:
 * obliga a abrir la campaña para averiguar qué mira, y a la tercera vez deja de
 * mirarse.
 *
 * ### Esta tabla NO se recorta por periodo
 *
 * Los filtros de cliente, país, sociedad y campaña sí se aplican; el periodo
 * **no**. Es el mismo criterio de `DEC-311` para las alertas: una campaña
 * retrasada lo está hoy, y esconderla porque terminó fuera de «los últimos 30
 * días» sería esconder justamente el caso que esta tabla existe para enseñar.
 * La cabecera de la sección lo dice, para que nadie lo lea como un filtro roto.
 *
 * ### La fecha la pone PHP, nunca el motor
 *
 * `DEC-302` costó un día de retraso silencioso en cada cierre de permanencia:
 * `CURDATE()` es el reloj del servidor de base de datos y todo lo demás va en
 * UTC. Aquí no hay ni una fecha en SQL: la consulta trae números y fechas
 * crudas, y quien compara con «hoy» es PHP con `now()`.
 *
 * ### Ni `WITH` ni funciones de ventana
 *
 * Producción es MySQL 5.7. Los conteos por campaña van como subconsultas
 * correlacionadas en el `SELECT` y no como `JOIN`s: tres `JOIN`s a mercados,
 * participaciones y entregables multiplicarían las filas y cada número saldría
 * mal por un factor distinto. Es el «evitar dobles conteos» del encargo, en el
 * único sitio donde de verdad se pierde.
 */
final class Semaforo
{
    public const ROJA = 'roja';

    public const AMBAR = 'ambar';

    public const VERDE = 'verde';

    public const TODAS = 'todas';

    /** @var array<string, string> Cómo se llama cada nivel en pantalla. */
    public const NIVELES = [
        self::ROJA => 'Retrasadas',
        self::AMBAR => 'En riesgo',
        self::VERDE => 'En plazo',
    ];

    /** En qué orden salen. Lo que arde, arriba. */
    private const ORDEN = [self::ROJA => 0, self::AMBAR => 1, self::VERDE => 2];

    /** Un entregable cuenta como avance desde que se aprueba. */
    public const LOGRADOS = ['approved', 'published', 'verified'];

    /** Una publicación cuenta como hecha cuando se verificó o cumplió su permanencia. */
    public const PUBLICADAS = ['verified', 'fulfilled'];

    /** Una campaña cerrada o cancelada ya no se vigila. */
    public const CERRADAS = ['completed', 'cancelled'];

    /** Una campaña que todavía no se aprobó tampoco: no ha prometido nada. */
    public const SIN_EMPEZAR = ['draft', 'pending_approval'];

    /**
     * Cuántas campañas se traen como mucho.
     *
     * No es un límite de negocio, es una defensa: el nivel se calcula en PHP
     * —tiene que serlo, porque los umbrales son configuración—, así que ordenar
     * por color obliga a traer el conjunto entero. Con las campañas activas de
     * una operación real esto no se toca ni de lejos; si algún día se toca, la
     * pantalla lo dice en vez de mentir por lo bajo (`T-106`).
     */
    public const TECHO = 500;

    /** Los valores de partida que aprobó el negocio (`DEC-319`). */
    public const PARTIDA = ['dias' => 7, 'avance' => 60, 'convocatoria' => 5];

    // ------------------------------------------------------------- umbrales

    /**
     * Los tres números, siempre completos.
     *
     * Sin fila —instalación a medias, alguien que la borró— devuelve los de
     * partida y `configurado` en `false`. Nunca `null`, y nunca una excepción:
     * un panel que se apaga porque falta una fila de configuración es lo que
     * `DEC-190` prohíbe expresamente.
     *
     * @return array{dias: int, avance: int, convocatoria: int, configurado: bool}
     */
    public static function umbrales(): array
    {
        $fila = self::fila();

        return [
            'dias' => (int) ($fila->risk_days_before_end ?? self::PARTIDA['dias']),
            'avance' => (int) ($fila->min_progress_pct ?? self::PARTIDA['avance']),
            'convocatoria' => (int) ($fila->recruiting_days_before_start ?? self::PARTIDA['convocatoria']),
            'configurado' => $fila !== null,
        ];
    }

    public static function fila(): ?object
    {
        if (!Schema::hasTable('tracking_thresholds')) {
            return null;
        }

        return DB::table('tracking_thresholds')->where('singleton', 1)->first();
    }

    /**
     * Guarda los tres números.
     *
     * `updateOrInsert` y no `update`: si la fila no está —y `umbrales()` está
     * sosteniendo la pantalla con los de partida— guardar tiene que dejarla
     * puesta, no fallar en silencio sobre cero filas afectadas.
     *
     * @param array{risk_days_before_end: int, min_progress_pct: int, recruiting_days_before_start: int} $datos
     */
    public static function guardar(array $datos, ?int $usuarioId): void
    {
        $antes = (array) (self::fila() ?? new \stdClass);

        DB::table('tracking_thresholds')->updateOrInsert(
            ['singleton' => 1],
            $datos + [
                'updated_by_user_id' => $usuarioId,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        // Cambiar un umbral repinta la pantalla que el equipo usa para decidir
        // que atender. Quien lo movio y cuando tiene que quedar escrito, y es
        // ademas lo que sustituye a versionar la tabla.
        Bitacora::registrar('tracking_thresholds.updated', 'tracking_thresholds', 1,
            Bitacora::diferencias($antes, $datos));
    }

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        $umbrales = self::umbrales();

        if (!$umbrales['configurado']) {
            return [Aviso::ambar(sprintf(
                'Los umbrales del semáforo no están guardados. Mientras tanto el panel usa los '
                .'valores de partida —%d días antes del cierre, %d %% de avance, %d días antes del '
                .'arranque— y funciona con normalidad; conviene confirmarlos con datos reales.',
                $umbrales['dias'], $umbrales['avance'], $umbrales['convocatoria'],
            ))];
        }

        // Un umbral de avance en 0 apaga la mitad ambar de la regla: ninguna
        // campana puede ir por debajo de cero. No es un error --puede ser
        // deliberado-- pero tiene que decirse, porque desde fuera se ve igual
        // que un semaforo que funciona.
        if ($umbrales['avance'] === 0) {
            return [Aviso::ambar(
                'El avance mínimo está en 0 %: con ese valor ninguna campaña puede salir en riesgo '
                .'por ir retrasada de contenido. Si es a propósito, no hay nada que hacer.',
            )];
        }

        return [];
    }

    // ------------------------------------------------------------- el nivel

    /**
     * De qué color va esta campaña, y por qué.
     *
     * Pura a propósito: sin base de datos, sin reloj propio y sin configuración
     * implícita. Todo lo que decide entra por parámetro, que es lo que permite
     * probar las seis ramas con datos escritos a mano y saber de antemano el
     * resultado.
     *
     * @param array{estado: string, inicio: string, fin: ?string, limite_publicacion: ?string,
     *              avance: ?float, aceptados: int, objetivo: int, sin_publicar: int} $campana
     * @param array{dias: int, avance: int, convocatoria: int} $umbrales
     * @return array{nivel: string, motivo: string}
     */
    public static function nivel(array $campana, array $umbrales, string $hoy): array
    {
        $estado = $campana['estado'];

        if (in_array($estado, self::CERRADAS, true)) {
            return ['nivel' => self::VERDE, 'motivo' => 'Cerrada.'];
        }

        // --- Roja: una fecha comprometida que ya pasó -----------------------

        if ($campana['fin'] !== null && $campana['fin'] < $hoy) {
            return ['nivel' => self::ROJA, 'motivo' => sprintf(
                'Terminaba el %s y sigue abierta.', self::comoFecha($campana['fin']),
            )];
        }

        if ($campana['limite_publicacion'] !== null
            && $campana['limite_publicacion'] < $hoy
            && $campana['sin_publicar'] > 0) {
            return ['nivel' => self::ROJA, 'motivo' => sprintf(
                'La fecha límite de publicación fue el %s y %s sin publicación verificada.',
                self::comoFecha($campana['limite_publicacion']),
                $campana['sin_publicar'] === 1
                    ? 'queda 1 entregable'
                    : sprintf('quedan %d entregables', $campana['sin_publicar']),
            )];
        }

        if (in_array($estado, self::SIN_EMPEZAR, true)) {
            return ['nivel' => self::VERDE, 'motivo' => 'Todavía no ha empezado.'];
        }

        // --- Ámbar: llega justa ---------------------------------------------

        if ($campana['fin'] !== null && $campana['avance'] !== null) {
            $faltan = self::dias($hoy, $campana['fin']);

            if ($faltan <= $umbrales['dias'] && $campana['avance'] < (float) $umbrales['avance']) {
                return ['nivel' => self::AMBAR, 'motivo' => sprintf(
                    '%s y el avance va en %s %% (el mínimo configurado es %d %%).',
                    $faltan === 0 ? 'Termina hoy' : sprintf('Faltan %d días para el cierre', $faltan),
                    self::comoNumero($campana['avance']),
                    $umbrales['avance'],
                )];
            }
        }

        if ($estado === 'recruiting'
            && $campana['objetivo'] > 0
            && $campana['aceptados'] < $campana['objetivo']
            && self::dias($hoy, $campana['inicio']) <= $umbrales['convocatoria']) {
            return ['nivel' => self::AMBAR, 'motivo' => sprintf(
                'Sigue en convocatoria con %d de %d creadores y arranca %s.',
                $campana['aceptados'], $campana['objetivo'],
                self::cuando(self::dias($hoy, $campana['inicio'])),
            )];
        }

        return ['nivel' => self::VERDE, 'motivo' => 'En plazo.'];
    }

    /**
     * El avance de contenido, en porcentaje.
     *
     * Sin entregables no hay nada que medir **si la campaña aún no arrancó**:
     * devuelve `null` y la regla del avance no se le aplica. Pero una campaña ya
     * arrancada y sin un solo entregable creado va al 0 %, que es la verdad: no
     * se ha producido nada. Tratar los dos casos igual escondería el segundo,
     * que es el que duele.
     */
    public static function avance(int $total, int $logrados, string $inicio, string $hoy): ?float
    {
        if ($total > 0) {
            return round($logrados / $total * 100, 1);
        }

        return $inicio <= $hoy ? 0.0 : null;
    }

    // ------------------------------------------------------------- la tabla

    /**
     * Las campañas vivas con su semáforo puesto.
     *
     * @param string $nivel Uno de `ROJA`/`AMBAR`/`VERDE`, o `TODAS`.
     * @return array{
     *     umbrales: array{dias: int, avance: int, convocatoria: int, configurado: bool},
     *     filas: list<array<string, mixed>>,
     *     conteo: array<string, int>,
     *     total: int,
     *     techo: bool,
     *     tope: int,
     *     niveles: array<string, string>,
     *     hoy: string
     * }
     */
    public static function campanas(FiltrosDeResumen $filtros, string $nivel = self::TODAS): array
    {
        $umbrales = self::umbrales();
        $hoy = now()->toDateString();
        $filas = [];
        $conteo = [self::ROJA => 0, self::AMBAR => 0, self::VERDE => 0];

        foreach (self::crudas($filtros) as $cruda) {
            $avance = self::avance(
                (int) $cruda->entregables, (int) $cruda->logrados,
                (string) $cruda->starts_on, $hoy,
            );

            $campana = [
                'estado' => (string) $cruda->status,
                'inicio' => (string) $cruda->starts_on,
                'fin' => $cruda->ends_on === null ? null : (string) $cruda->ends_on,
                'limite_publicacion' => $cruda->publication_deadline === null
                    ? null : (string) $cruda->publication_deadline,
                'avance' => $avance,
                'aceptados' => (int) $cruda->aceptados,
                'objetivo' => (int) $cruda->objetivo,
                'sin_publicar' => (int) $cruda->sin_publicar,
            ];

            $veredicto = self::nivel($campana, $umbrales, $hoy);
            $conteo[$veredicto['nivel']]++;

            $filas[] = $campana + $veredicto + [
                'id' => (int) $cruda->id,
                'codigo' => (string) $cruda->code,
                'nombre' => (string) $cruda->name,
                'cliente' => (string) $cruda->cliente,
                'entregables' => (int) $cruda->entregables,
                'logrados' => (int) $cruda->logrados,
                'dias' => $cruda->ends_on === null ? null : self::dias($hoy, (string) $cruda->ends_on),
                // Ya formateados: la plantilla no parte fechas ni consulta
                // constantes de una clase de negocio (`docs/08`).
                'fin_texto' => $cruda->ends_on === null ? null : self::comoFecha((string) $cruda->ends_on),
                'nivel_texto' => self::NIVELES[$veredicto['nivel']],
            ];
        }

        $total = count($filas);

        usort($filas, static function (array $a, array $b): int {
            return [self::ORDEN[$a['nivel']], $a['fin'] ?? '9999-12-31', $a['id']]
                <=> [self::ORDEN[$b['nivel']], $b['fin'] ?? '9999-12-31', $b['id']];
        });

        if ($nivel !== self::TODAS) {
            $filas = array_values(array_filter(
                $filas, static fn (array $fila): bool => $fila['nivel'] === $nivel,
            ));
        }

        return [
            'umbrales' => $umbrales,
            'filas' => $filas,
            'conteo' => $conteo,
            'total' => $total,
            'techo' => $total >= self::TECHO,
            'tope' => self::TECHO,
            'niveles' => [self::TODAS => 'Todas'] + self::NIVELES,
            'hoy' => $hoy,
        ];
    }

    /**
     * Las campañas vivas, con sus conteos ya hechos por el motor.
     *
     * Cada número es una subconsulta correlacionada. Se paga con una consulta
     * por campaña y por columna, y se cobra en que **ninguna multiplica filas**:
     * con `JOIN`s, una campaña con tres mercados y cuatro participantes daría
     * doce entregables donde hay cuatro.
     *
     * @return Collection<int, \stdClass>
     */
    private static function crudas(FiltrosDeResumen $filtros): Collection
    {
        $participando = self::marcadores(Resumen::PARTICIPANDO);
        $logrados = self::marcadores(self::LOGRADOS);
        $publicadas = self::marcadores(self::PUBLICADAS);

        $consulta = DB::table('campaigns')
            ->join('client_organizations as co', 'co.id', '=', 'campaigns.client_organization_id')
            ->whereIn('campaigns.status', Resumen::ACTIVAS);

        Resumen::recortar($consulta, $filtros);

        return $consulta
            ->select([
                'campaigns.id', 'campaigns.code', 'campaigns.name', 'campaigns.status',
                'campaigns.starts_on', 'campaigns.ends_on', 'campaigns.publication_deadline',
                // `commercial_name` y no la razon social: `client_organizations`
                // no la tiene, y no por olvido --vive en el perfil fiscal, que
                // es POR PAIS y puede ser distinta en cada uno--. El nombre con
                // el que se le conoce es ademas el que se quiere leer aqui.
                DB::raw('co.commercial_name as cliente'),
            ])
            ->selectRaw(
                '(SELECT COUNT(*) FROM campaign_creators cc WHERE cc.campaign_id = campaigns.id '
                ."AND cc.status IN ($participando)) as aceptados",
                Resumen::PARTICIPANDO,
            )
            ->selectRaw(
                '(SELECT COALESCE(SUM(cm.target_creators), 0) FROM campaign_markets cm '
                .'WHERE cm.campaign_id = campaigns.id) as objetivo',
            )
            // El denominador del avance. `cancelled` fuera: un entregable
            // cancelado no es trabajo pendiente. `removed` DENTRO: el contenido
            // se cayo, y esconderlo subiria el avance justo cuando empeora.
            ->selectRaw(
                '(SELECT COUNT(*) FROM deliverables d '
                .'INNER JOIN campaign_creators cd ON cd.id = d.campaign_creator_id '
                ."WHERE cd.campaign_id = campaigns.id AND d.status <> 'cancelled') as entregables",
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM deliverables d '
                .'INNER JOIN campaign_creators cd ON cd.id = d.campaign_creator_id '
                ."WHERE cd.campaign_id = campaigns.id AND d.status IN ($logrados)) as logrados",
                self::LOGRADOS,
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM deliverables d '
                .'INNER JOIN campaign_creators cd ON cd.id = d.campaign_creator_id '
                ."WHERE cd.campaign_id = campaigns.id AND d.status <> 'cancelled' "
                .'AND NOT EXISTS (SELECT 1 FROM publications p WHERE p.deliverable_id = d.id '
                ."AND p.status IN ($publicadas))) as sin_publicar",
                self::PUBLICADAS,
            )
            // Un orden explicito antes del techo: sin el, «las 500
            // primeras» seria lo que devuelva el motor ese dia.
            ->orderBy('campaigns.id')
            ->limit(self::TECHO)
            ->get();
    }

    // ------------------------------------------------------------- utilidad

    /**
     * `?,?,?` para una lista de estados que va como enlaces, nunca concatenada.
     *
     * @param list<string> $lista
     */
    private static function marcadores(array $lista): string
    {
        return implode(',', array_fill(0, count($lista), '?'));
    }

    /**
     * Días enteros entre dos fechas `Y-m-d`, con signo.
     *
     * `round()` antes de `(int)` no es un adorno: en Carbon 3 `diffInDays`
     * devuelve un `float`, y truncar `6,999999` da 6. Eso ya costó un periodo
     * de comparación de más un día entero en `DEC-314`.
     */
    public static function dias(string $desde, string $hasta): int
    {
        return (int) round(
            CarbonImmutable::parse($desde)->startOfDay()
                ->diffInDays(CarbonImmutable::parse($hasta)->startOfDay(), false),
        );
    }

    private static function cuando(int $dias): string
    {
        if ($dias < 0) {
            return sprintf('debería haber arrancado hace %d días', abs($dias));
        }

        return match ($dias) {
            0 => 'hoy',
            1 => 'mañana',
            default => sprintf('en %d días', $dias),
        };
    }

    private static function comoFecha(string $fecha): string
    {
        return CarbonImmutable::parse($fecha)->format('d/m/Y');
    }

    private static function comoNumero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 1, ',', ''), '0'), ',');
    }
}
