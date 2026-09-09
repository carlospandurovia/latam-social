<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los números del centro de control (`D-2`).
 *
 * ### Un indicador de punta a punta antes que treinta
 *
 * Esta clase nace con **uno solo**: campañas activas. No es poco ambicioso, es
 * el orden correcto. Antes de escribir treinta consultas hay que demostrar que
 * una viaja entera —filtro elegido en la pantalla, recorte aplicado, número
 * calculado, comparación con el periodo anterior, y clic que lleva al detalle
 * con el mismo recorte puesto—. Lo que se rompe en un panel no suele ser la
 * consulta: es la tubería.
 *
 * Los demás entran en `D-3` y siguientes, y cada uno trae su prueba con datos
 * fabricados donde el número esperado se conoce de antemano. Con la base vacía
 * —cero campañas, cero clientes— **todo sale en cero**, y una fórmula
 * equivocada se ve exactamente igual que una correcta.
 *
 * ### Nada de `ROW_NUMBER()` ni de `WITH`
 *
 * Este sistema corre en producción sobre MySQL 5.7, que no tiene ninguna de las
 * dos. No es una preferencia de estilo: una consulta con funciones de ventana
 * pasaría las pruebas —el contenedor usa MariaDB— y reventaría en el servidor.
 * Es la misma familia que `DEC-304`. Aquí sólo hay agregados y subconsultas.
 *
 * ### Contar campañas y no filas
 *
 * Una campaña con tres mercados aparece tres veces si se cruza con
 * `campaign_markets`. El filtro por país va con `EXISTS` y no con `JOIN` por
 * eso: recorta sin multiplicar. Es el «evitar dobles conteos» del encargo, y el
 * sitio donde de verdad se pierde.
 */
final class Resumen
{
    /** Los estados en los que una campaña está viva y en marcha. */
    public const ACTIVAS = ['approved', 'recruiting', 'in_progress', 'in_review'];

    /** Un creador está participando desde que acepta hasta que se cierra su parte. */
    public const PARTICIPANDO = [
        'accepted', 'in_production', 'delivered', 'approved', 'published', 'verified',
    ];

    /** Cuándo se calculó lo que se está viendo. */
    public static function actualizadoEn(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * Campañas activas, con su comparación.
     *
     * @return array{
     *     titulo: string, valor: int, anterior: int, variacion: ?float,
     *     sentido: string, tooltip: string, ruta: string
     * }
     */
    public static function campanasActivas(FiltrosDeResumen $filtros): array
    {
        $valor = self::contarCampanasActivas($filtros);
        $anterior = self::contarCampanasActivas($filtros->anterior());

        return [
            'titulo' => 'Campañas activas',
            'valor' => $valor,
            'anterior' => $anterior,
            'variacion' => self::variacion($valor, $anterior),
            'sentido' => self::sentido($valor, $anterior),
            'tooltip' => 'Campañas aprobadas, en convocatoria, en ejecución o en revisión '
                .'cuya ventana de fechas toca el periodo elegido. Una campaña con varios '
                .'mercados cuenta una vez.',
            'ruta' => 'campanas.index',
        ];
    }

    /**
     * El recorte, aplicado igual para todos los indicadores.
     *
     * Vive en un solo sitio a propósito: el día que se añada un filtro, el
     * bloque nuevo lo hereda sin que nadie tenga que acordarse.
     */
    public static function recortar(Builder $consulta, FiltrosDeResumen $filtros): Builder
    {
        if ($filtros->clienteId !== null) {
            $consulta->where('campaigns.client_organization_id', $filtros->clienteId);
        }

        if ($filtros->sociedadId !== null) {
            $consulta->where('campaigns.billing_legal_entity_id', $filtros->sociedadId);
        }

        if ($filtros->campanaId !== null) {
            $consulta->where('campaigns.id', $filtros->campanaId);
        }

        if ($filtros->paisId !== null) {
            $consulta->whereExists(static function (Builder $sub) use ($filtros): void {
                $sub->select(DB::raw(1))
                    ->from('campaign_markets as cm')
                    ->whereColumn('cm.campaign_id', 'campaigns.id')
                    ->where('cm.country_id', $filtros->paisId);
            });
        }

        return $consulta;
    }

    /**
     * Los indicadores de operación (`D-4`).
     *
     * ### Flujo o existencias, y dicho en cada uno
     *
     * Hay dos clases de número y confundirlas es el error clásico de un panel:
     *
     * - **Flujo**: lo que PASÓ dentro del periodo —campañas que arrancaron,
     *   entregables que llegaron—. Cambia con el periodo, como debe.
     * - **Existencias**: lo que HAY, recortado a lo que toca el periodo —campañas
     *   activas, creadores participando—.
     *
     * Un número de existencias con un periodo pasado engaña: «campañas por
     * iniciar» filtrado a «últimos 30 días» daría cero siempre, porque nada que
     * empiece mañana cae en un periodo que terminó hoy. Por eso ese indicador
     * es **«campañas que arrancan en el periodo»** y no «pendientes de iniciar»:
     * la segunda pregunta ya la contesta la sección de alertas, que mira el
     * presente y no se recorta (`DEC-311`).
     *
     * Cada tarjeta lo dice en su tooltip. Un KPI cuya definición hay que
     * adivinar es un KPI que cada persona interpreta a su manera.
     *
     * @return list<array{titulo: string, valor: int, anterior: int, variacion: ?float,
     *                    sentido: string, tooltip: string, ruta: string}>
     */
    public static function operacion(FiltrosDeResumen $filtros): array
    {
        return [
            self::campanasActivas($filtros),
            self::tarjeta(
                'Campañas que arrancan',
                'Campañas cuya fecha de inicio cae dentro del periodo, sin contar '
                .'borradores ni canceladas. Mide flujo: cambia con el periodo.',
                'campanas.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::contar(
                    'campaigns',
                    static fn ($q) => $q
                        ->whereNotIn('campaigns.status', ['draft', 'cancelled'])
                        ->whereBetween('campaigns.starts_on', [
                            $f->desde->toDateString(), $f->hasta->toDateString(),
                        ]),
                    $f,
                ),
            ),
            self::tarjeta(
                'Campañas cerradas',
                'Campañas que se cerraron dentro del periodo. La fecha es '
                .'`closed_at`, no `ends_on`: una campaña puede terminar su ventana '
                .'y seguir abierta hasta que se cierra de verdad.',
                'campanas.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::contar(
                    'campaigns',
                    static fn ($q) => $q->whereBetween('campaigns.closed_at', [
                        $f->desde->toDateTimeString(), $f->hasta->toDateTimeString(),
                    ]),
                    $f,
                ),
            ),
            self::tarjeta(
                'Creadores participando',
                'Creadores distintos con una participación aceptada o en marcha en '
                .'campañas que tocan el periodo. Quien está en tres campañas cuenta una vez.',
                'creadores.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::creadoresParticipando($f),
            ),
            self::tarjeta(
                'Entregables entregados',
                'Entregables que el creador envió dentro del periodo. Los que siguen '
                .'esperando revisión están en «Requiere atención», que mira el presente.',
                'revision.cola',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::entregablesEntregados($f),
            ),
        ];
    }

    /**
     * Una tarjeta con su comparación, para no repetir cinco veces lo mismo.
     *
     * @param \Closure(FiltrosDeResumen): int $contar
     * @return array{titulo: string, valor: int, anterior: int, variacion: ?float,
     *               sentido: string, tooltip: string, ruta: string}
     */
    private static function tarjeta(
        string $titulo,
        string $tooltip,
        string $ruta,
        FiltrosDeResumen $filtros,
        \Closure $contar,
    ): array {
        $valor = $contar($filtros);
        $anterior = $contar($filtros->anterior());

        return [
            'titulo' => $titulo,
            'valor' => $valor,
            'anterior' => $anterior,
            'variacion' => self::variacion($valor, $anterior),
            'sentido' => self::sentido($valor, $anterior),
            'tooltip' => $tooltip,
            'ruta' => $ruta,
        ];
    }

    /** Cuenta sobre `campaigns` con el recorte puesto. */
    private static function contar(string $tabla, \Closure $condicion, FiltrosDeResumen $filtros): int
    {
        if (!DB::getSchemaBuilder()->hasTable($tabla)) {
            return 0;
        }

        $consulta = DB::table($tabla);
        $condicion($consulta);

        return self::recortar($consulta, $filtros)->count();
    }

    /**
     * Creadores DISTINTOS, no participaciones.
     *
     * `COUNT(DISTINCT creator_id)` y no `COUNT(*)`: quien participa en tres
     * campañas del periodo es un creador, no tres. Sin el `DISTINCT` el panel
     * enseñaría más red de la que hay, y crecería justo cuando la operación se
     * concentra en pocos creadores, que es lo contrario de lo que pasa.
     */
    private static function creadoresParticipando(FiltrosDeResumen $filtros): int
    {
        if (!DB::getSchemaBuilder()->hasTable('campaign_creators')) {
            return 0;
        }

        $consulta = DB::table('campaign_creators as cc')
            ->join('campaigns', 'campaigns.id', '=', 'cc.campaign_id')
            ->whereIn('cc.status', self::PARTICIPANDO)
            ->where('campaigns.starts_on', '<=', $filtros->hasta->toDateString())
            ->whereRaw('IFNULL(campaigns.ends_on, ?) >= ?', [
                $filtros->hasta->toDateString(), $filtros->desde->toDateString(),
            ]);

        return self::recortar($consulta, $filtros)->distinct()->count('cc.creator_id');
    }

    /** Entregables enviados dentro del periodo, por la campaña a la que pertenecen. */
    private static function entregablesEntregados(FiltrosDeResumen $filtros): int
    {
        if (!DB::getSchemaBuilder()->hasTable('deliverables')) {
            return 0;
        }

        $consulta = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns', 'campaigns.id', '=', 'cc.campaign_id')
            ->whereBetween('d.submitted_at', [
                $filtros->desde->toDateTimeString(), $filtros->hasta->toDateTimeString(),
            ]);

        return self::recortar($consulta, $filtros)->count();
    }

    /** Los ocho estados que `ck_camp_status` permite, en el orden en que ocurren. */
    public const ESTADOS = [
        'draft' => 'Borrador',
        'pending_approval' => 'Pendiente de aprobación',
        'approved' => 'Aprobada',
        'recruiting' => 'Convocatoria abierta',
        'in_progress' => 'En ejecución',
        'in_review' => 'En revisión',
        'completed' => 'Completada',
        'cancelled' => 'Cancelada',
    ];

    /**
     * Cuántas campañas hay en cada estado, y cuánto dinero representan (`D-5`).
     *
     * Salen **los ocho**, también los que están a cero. Un estado que desaparece
     * cuando se vacía obliga a recordar cuáles existen para notar que falta uno,
     * y «no hay ninguna en revisión» es una respuesta tan útil como «hay cuatro».
     *
     * El importe es `revenue_amount`: lo que se le cobra al cliente. No es el
     * costo de creadores ni el margen —eso vive en otra iteración y detrás de
     * `campaign.view_margin`—, así que este bloque lo puede ver quien lleva la
     * operación sin enseñarle de más.
     *
     * @return list<array{clave: string, nombre: string, cantidad: int, importe: float}>
     */
    public static function porEstado(FiltrosDeResumen $filtros): array
    {
        $crudo = [];

        if (DB::getSchemaBuilder()->hasTable('campaigns')) {
            $consulta = DB::table('campaigns')
                ->where('campaigns.starts_on', '<=', $filtros->hasta->toDateString())
                ->whereRaw('IFNULL(campaigns.ends_on, ?) >= ?', [
                    $filtros->hasta->toDateString(), $filtros->desde->toDateString(),
                ])
                ->groupBy('campaigns.status');

            foreach (self::recortar($consulta, $filtros)
                ->get(['campaigns.status', DB::raw('COUNT(*) AS cuantas'),
                    DB::raw('SUM(campaigns.revenue_amount) AS importe')]) as $fila) {
                $crudo[(string) $fila->status] = [
                    'cantidad' => (int) $fila->cuantas,
                    'importe' => (float) $fila->importe,
                ];
            }
        }

        $filas = [];
        foreach (self::ESTADOS as $clave => $nombre) {
            $filas[] = [
                'clave' => $clave,
                'nombre' => $nombre,
                'cantidad' => $crudo[$clave]['cantidad'] ?? 0,
                'importe' => $crudo[$clave]['importe'] ?? 0.0,
            ];
        }

        return $filas;
    }

    /**
     * El embudo operativo (`D-5`).
     *
     * ### `DEC-317`: cada peldaño cuenta SU unidad, y lo dice
     *
     * Un embudo de conversión normal cuenta lo mismo en todos los peldaños —mil
     * visitas, cien registros, diez compras— y por eso se puede dividir. Éste no
     * puede: una campaña tiene varios creadores, cada creador varios entregables
     * y cada entregable una publicación. Dividir «publicaciones» entre «campañas»
     * daría un número con pinta de tasa de conversión y significado ninguno.
     *
     * Así que **no se calculan porcentajes entre peldaños** y cada uno dice en
     * qué unidad está. Es menos vistoso y es lo único honesto: un embudo cuyos
     * porcentajes no significan nada se usa para decidir igual, y ahí está el
     * daño.
     *
     * @return list<array{etiqueta: string, unidad: string, cantidad: int}>
     */
    public static function embudo(FiltrosDeResumen $filtros): array
    {
        $enElPeriodo = static function (Builder $q) use ($filtros) {
            return $q->where('campaigns.starts_on', '<=', $filtros->hasta->toDateString())
                ->whereRaw('IFNULL(campaigns.ends_on, ?) >= ?', [
                    $filtros->hasta->toDateString(), $filtros->desde->toDateString(),
                ]);
        };

        $campanas = static function (?\Closure $extra = null) use ($filtros, $enElPeriodo): int {
            if (!DB::getSchemaBuilder()->hasTable('campaigns')) {
                return 0;
            }
            $q = $enElPeriodo(DB::table('campaigns'));
            if ($extra !== null) {
                $extra($q);
            }

            return self::recortar($q, $filtros)->count();
        };

        return [
            [
                'etiqueta' => 'Campañas en marcha',
                'unidad' => 'campañas',
                'cantidad' => $campanas(static fn ($q) => $q->whereNotIn('campaigns.status', ['draft', 'cancelled'])),
            ],
            [
                'etiqueta' => 'Con convocatoria abierta o pasada',
                'unidad' => 'campañas',
                'cantidad' => $campanas(static fn ($q) => $q->whereIn('campaigns.status', [
                    'recruiting', 'in_progress', 'in_review', 'completed',
                ])),
            ],
            [
                'etiqueta' => 'Creadores seleccionados',
                'unidad' => 'participaciones',
                'cantidad' => self::contarUnido('campaign_creators as cc', 'cc.campaign_id',
                    static fn ($q) => $q->whereIn('cc.status', self::PARTICIPANDO), $filtros, $enElPeriodo),
            ],
            [
                'etiqueta' => 'Contenidos aprobados',
                'unidad' => 'entregables',
                'cantidad' => self::contarEntregables(
                    ['approved', 'published', 'verified'], $filtros, $enElPeriodo),
            ],
            [
                'etiqueta' => 'Publicaciones verificadas',
                'unidad' => 'publicaciones',
                'cantidad' => self::contarPublicaciones($filtros, $enElPeriodo),
            ],
            [
                'etiqueta' => 'Campañas cerradas',
                'unidad' => 'campañas',
                'cantidad' => $campanas(static fn ($q) => $q->whereNotNull('campaigns.closed_at')),
            ],
        ];
    }

    /** @param \Closure(Builder): Builder $enElPeriodo */
    private static function contarUnido(
        string $tabla,
        string $columnaCampana,
        \Closure $extra,
        FiltrosDeResumen $filtros,
        \Closure $enElPeriodo,
    ): int {
        $nombre = explode(' as ', $tabla)[0];
        if (!DB::getSchemaBuilder()->hasTable($nombre)) {
            return 0;
        }

        $q = DB::table($tabla)->join('campaigns', 'campaigns.id', '=', $columnaCampana);
        $enElPeriodo($q);
        $extra($q);

        return self::recortar($q, $filtros)->count();
    }

    /**
     * @param list<string> $estados
     * @param \Closure(Builder): Builder $enElPeriodo
     */
    private static function contarEntregables(array $estados, FiltrosDeResumen $filtros, \Closure $enElPeriodo): int
    {
        if (!DB::getSchemaBuilder()->hasTable('deliverables')) {
            return 0;
        }

        $q = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns', 'campaigns.id', '=', 'cc.campaign_id')
            ->whereIn('d.status', $estados);
        $enElPeriodo($q);

        return self::recortar($q, $filtros)->count();
    }

    /** @param \Closure(Builder): Builder $enElPeriodo */
    private static function contarPublicaciones(FiltrosDeResumen $filtros, \Closure $enElPeriodo): int
    {
        if (!DB::getSchemaBuilder()->hasTable('publications')) {
            return 0;
        }

        // El mismo join que `basePublicaciones()`, que es de donde sale desde
        // `D-14`: dos copias del mismo recorrido divergen a la segunda vez que
        // alguien toca una.
        $q = self::basePublicaciones()->whereIn('p.status', self::PUBLICADAS_VIVAS);
        $enElPeriodo($q);

        return self::recortar($q, $filtros)->count();
    }

    /**
     * Lo que se puede elegir en cada desplegable.
     *
     * Sólo lo que EXISTE. Un desplegable de países con los 195 del mundo cuando
     * la operación toca tres es una lista que hay que leer entera para no
     * encontrar nada; aquí salen los que tienen campañas, y punto.
     *
     * Cuando la operación crezca, estos desplegables dejarán de servir y habrá
     * que cambiarlos por un buscador (`T-102`). Hoy, con la operación arrancando,
     * un `SELECT` corto es lo correcto y lo más rápido de usar.
     *
     * @return array{paises: list<object>, sociedades: list<object>, clientes: list<object>, campanas: list<object>}
     */
    public static function opciones(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('campaigns')) {
            return ['paises' => [], 'sociedades' => [], 'clientes' => [], 'campanas' => []];
        }

        return [
            'paises' => DB::table('countries as c')
                ->join('campaign_markets as cm', 'cm.country_id', '=', 'c.id')
                ->distinct()->orderBy('c.name')
                ->get(['c.id', 'c.name as nombre'])->all(),
            'sociedades' => DB::table('legal_entities as le')
                ->whereIn('le.id', DB::table('campaigns')->whereNotNull('billing_legal_entity_id')
                    ->distinct()->pluck('billing_legal_entity_id'))
                ->orderBy('le.code')
                ->get(['le.id', 'le.code as nombre'])->all(),
            'clientes' => DB::table('client_organizations as co')
                ->whereIn('co.id', DB::table('campaigns')->distinct()->pluck('client_organization_id'))
                ->orderBy('co.commercial_name')
                ->get(['co.id', 'co.commercial_name as nombre'])->all(),
            'campanas' => DB::table('campaigns')
                ->orderBy('name')->limit(200)
                ->get(['id', 'name as nombre'])->all(),
        ];
    }

    private static function contarCampanasActivas(FiltrosDeResumen $filtros): int
    {
        if (!DB::getSchemaBuilder()->hasTable('campaigns')) {
            return 0;
        }

        $consulta = DB::table('campaigns')
            ->whereIn('campaigns.status', self::ACTIVAS)
            // La ventana de la campaña TOCA el periodo. No «empieza dentro»:
            // una campaña de tres meses no aparecería en ninguna semana suya,
            // que es justo lo contrario de lo que se está preguntando.
            ->where('campaigns.starts_on', '<=', $filtros->hasta->toDateString())
            ->whereRaw('IFNULL(campaigns.ends_on, ?) >= ?', [
                $filtros->hasta->toDateString(),
                $filtros->desde->toDateString(),
            ]);

        return self::recortar($consulta, $filtros)->count();
    }

    /** Null cuando no hay con qué comparar: un porcentaje sobre cero es una invención. */
    // ------------------------------------------------------------ comercial

    /**
     * Los estados de un prospecto, con su nombre en pantalla (`D-7`).
     *
     * Salen los cinco siempre, también a cero: un estado que desaparece al
     * vaciarse obliga a acordarse de cuáles existen para notar que falta uno.
     */
    public const PROSPECTOS = [
        'new' => 'Sin atender',
        'contacted' => 'Contactados',
        'qualified' => 'Calificados',
        'converted' => 'Convertidos en cliente',
        'discarded' => 'Descartados',
    ];

    /** Un prospecto sigue vivo mientras no se descarte ni se convierta. */
    public const PROSPECTOS_VIVOS = ['new', 'contacted', 'qualified'];

    /** Una solicitud de creador está abierta hasta que alguien la resuelve. */
    public const SOLICITUDES_ABIERTAS = ['submitted', 'in_review'];

    /**
     * Los indicadores de comercial (`D-7`).
     *
     * ### Los cuatro son FLUJO, y no por casualidad
     *
     * «Clientes activos» y «prospectos sin atender» son EXISTENCIAS, y las dos
     * se dejaron fuera a propósito:
     *
     * - **Lo pendiente ya lo dice el bloque de alertas** —`prospectos_sin_atender`
     *   y `solicitudes_creador` desde `D-3`— y ahí mira el presente sin
     *   recortarse (`DEC-311`). Repetirlo aquí, recortado por periodo, daría dos
     *   números distintos para la misma pregunta en la misma pantalla. Ese es el
     *   fallo que hace que un panel deje de creerse.
     * - **«Clientes activos» no se puede comparar honestamente con el periodo
     *   anterior**: `status` es el de HOY, no hay historia de estados de un
     *   cliente, y contar «cuántos estaban activos en mayo» aplicando el estado
     *   de hoy a una fecha pasada es inventar. Entra cuando exista esa historia.
     *
     * Los cuatro que quedan se cuentan por una FECHA que no cambia —cuándo
     * llegó, cuándo se dio de alta—, así que comparar dos periodos significa
     * algo.
     *
     * @return list<array{titulo: string, valor: int, anterior: int, variacion: ?float,
     *                    sentido: string, tooltip: string, ruta: string}>
     */
    public static function comercial(FiltrosDeResumen $filtros): array
    {
        return [
            self::tarjeta(
                'Clientes nuevos',
                'Clientes dados de alta dentro del periodo, sea cual sea su estado hoy. '
                .'Se cuentan por su fecha de alta, que no cambia.',
                'clientes.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::clientesNuevos($f),
            ),
            self::tarjeta(
                'Prospectos recibidos',
                'Contactos que llegaron por el formulario público dentro del periodo. '
                .'Cuántos siguen sin atender lo dice el bloque de alertas, que mira el presente.',
                'prospectos.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::prospectos($f),
            ),
            self::tarjeta(
                'Prospectos convertidos',
                'De los prospectos recibidos EN EL PERIODO, los que ya son cliente. '
                .'Un prospecto de marzo que se convierte en mayo cuenta en marzo: así la '
                .'conversión de un periodo no cambia según cuándo se mire.',
                'prospectos.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::prospectosConvertidos($f),
            ),
            self::tarjeta(
                'Solicitudes de creador',
                'Creadores que se postularon dentro del periodo. Las que están sin resolver '
                .'salen en alertas.',
                'solicitudes.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::solicitudes($f),
            ),
        ];
    }

    /**
     * Los prospectos del periodo repartidos por el estado en que están HOY.
     *
     * ### Es una DISTRIBUCIÓN, no un embudo, y la diferencia no es de estilo
     *
     * Un embudo necesita saber **por dónde pasó** cada prospecto. `client_leads`
     * guarda un solo `status`: el actual. Un prospecto convertido ya no se
     * cuenta en «contactados» aunque lo estuviera, así que dibujar estos cinco
     * números como un embudo enseñaría una figura que **parece un dato y no lo
     * es** —y que además se leería como una caída entre pasos que nunca ocurrió—.
     *
     * La historia existiría en `status_transitions`, pero **hoy nadie la escribe
     * para los prospectos**: sólo se registra la activación de un creador
     * (`Transicion::registrar` tiene un único llamador). Es la mesa puesta sin
     * la puerta, igual que `document_series` antes de `9.12`. Queda `T-113`.
     *
     * La conversión sí se puede dar, y se da como UN número explícito: los
     * convertidos entre el total recibido en el periodo. Eso no necesita
     * historia.
     *
     * @return array{filas: list<array{clave: string, nombre: string, cantidad: int}>,
     *               total: int, convertidos: int, conversion: ?float}
     */
    public static function prospectosPorEstado(FiltrosDeResumen $filtros): array
    {
        $cuentas = [];

        foreach (self::baseDeProspectos($filtros)
            ->groupBy('client_leads.status')
            ->select('client_leads.status', DB::raw('COUNT(*) as cuantos'))
            ->get() as $fila) {
            $cuentas[(string) $fila->status] = (int) $fila->cuantos;
        }

        $filas = [];
        $total = 0;

        foreach (self::PROSPECTOS as $clave => $nombre) {
            $cantidad = (int) ($cuentas[$clave] ?? 0);
            $total += $cantidad;
            $filas[] = ['clave' => $clave, 'nombre' => $nombre, 'cantidad' => $cantidad];
        }

        $convertidos = (int) ($cuentas['converted'] ?? 0);

        return [
            'filas' => $filas,
            'total' => $total,
            'convertidos' => $convertidos,
            // `null` y no 0 cuando no llegó ninguno: «0 % de conversión» sobre
            // cero prospectos es un juicio sobre algo que no pasó (`DEC-309`).
            'conversion' => $total === 0 ? null : round($convertidos / $total * 100, 1),
        ];
    }

    /**
     * El recorte que SÍ le aplica a comercial, y el que no.
     *
     * `recortar()` no sirve aquí: filtra por columnas de `campaigns`, y estas
     * tablas no las tienen. De los cuatro filtros de la barra, a un prospecto le
     * significan dos —el país, que declara, y ninguno más— porque un contacto
     * que todavía no es cliente no tiene ni sociedad que le facture ni campaña.
     * La cabecera del bloque lo dice con palabras: un filtro que parece aplicarse
     * y no se aplica es peor que uno que no está (`DEC-310`).
     */
    private static function baseDeProspectos(FiltrosDeResumen $filtros): Builder
    {
        $consulta = DB::table('client_leads')
            ->whereBetween('client_leads.submitted_at', [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ]);

        if ($filtros->paisId !== null) {
            $consulta->where('client_leads.country_id', $filtros->paisId);
        }

        return $consulta;
    }

    private static function prospectos(FiltrosDeResumen $filtros): int
    {
        return self::baseDeProspectos($filtros)->count();
    }

    private static function prospectosConvertidos(FiltrosDeResumen $filtros): int
    {
        // Por `client_organization_id` y no por `status='converted'`: el estado
        // se puede mover a mano, y la fila del cliente es el hecho.
        return self::baseDeProspectos($filtros)
            ->whereNotNull('client_leads.client_organization_id')->count();
    }

    private static function clientesNuevos(FiltrosDeResumen $filtros): int
    {
        $consulta = DB::table('client_organizations')
            ->whereBetween('created_at', [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ]);

        if ($filtros->paisId !== null) {
            $consulta->where('country_id', $filtros->paisId);
        }

        if ($filtros->clienteId !== null) {
            $consulta->where('id', $filtros->clienteId);
        }

        return $consulta->count();
    }

    private static function solicitudes(FiltrosDeResumen $filtros): int
    {
        $consulta = DB::table('creator_applications')
            ->whereBetween('submitted_at', [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ]);

        if ($filtros->paisId !== null) {
            $consulta->where('country_id', $filtros->paisId);
        }

        return $consulta->count();
    }

    // ----------------------------------------------------------- financiero

    /** Una factura cuenta desde que se emite. Borrador y anulada, nunca. */
    public const FACTURAS_VIVAS = ['issued', 'sent', 'paid', 'partially_paid'];

    /**
     * El resumen financiero, **por moneda y sin consolidar** (`D-8`).
     *
     * ### Por qué no hay un total único
     *
     * Porque sumar soles y dólares en un mismo número es la mentira más cara que
     * puede contar un panel, y la forma en que se cuenta suele ser peor que la
     * mentira: `Cambio::convertir()` devuelve `monto_destino => null` cuando **no
     * hay tasa para ese día**, así que un total consolidado a la ligera no sale
     * mal —sale **de menos**, en silencio, y sin decir cuánto se dejó fuera—.
     *
     * Un importe por moneda es siempre verdad y no necesita ninguna tasa. La
     * consolidación entra cuando esté decidida la moneda base (§4.3) y, con
     * ella, **qué hacer con lo que no se pudo convertir**: esa segunda mitad es
     * la que hace que la cifra se pueda creer, y es una decisión de negocio, no
     * una función.
     *
     * ### Flujo y existencias, otra vez
     *
     * Facturado, cobrado y pagado son **flujo**: se cuentan por su fecha —de
     * emisión, de cobro, de confirmación— dentro del periodo. «Por cobrar» es
     * **existencias a hoy** y no se recorta: una factura de hace cuatro meses
     * que nadie ha pagado es exactamente la que hay que ver, y filtrarla por el
     * periodo la escondería (`DEC-311`).
     *
     * @return array{
     *     bloques: list<array{clave: string, titulo: string, nota: string,
     *                         ruta: string, lineas: list<array{moneda: string, importe: float}>}>,
     *     monedas: list<string>
     * }
     */
    public static function financiero(FiltrosDeResumen $filtros): array
    {
        $desde = $filtros->desde->startOfDay();
        $hasta = $filtros->hasta->endOfDay();

        $facturado = self::porMoneda(
            DB::table('invoices')
                ->whereIn('status', self::FACTURAS_VIVAS)
                ->whereBetween('issue_date', [$desde->toDateString(), $hasta->toDateString()]),
            'invoices.currency_code', 'invoices.total_amount', $filtros, 'invoices.client_organization_id',
        );

        $cobrado = self::porMoneda(
            DB::table('payments')
                ->join('invoices as f', 'f.id', '=', 'payments.invoice_id')
                ->whereIn('f.status', self::FACTURAS_VIVAS)
                ->whereBetween('payments.received_on', [$desde->toDateString(), $hasta->toDateString()]),
            'payments.currency_code', 'payments.amount', $filtros, 'f.client_organization_id',
        );

        // Existencias: lo emitido y no cobrado A HOY. Se resta el cobro dentro
        // de una subconsulta correlacionada --nunca con un JOIN-- porque una
        // factura con tres cobros parciales se contaria tres veces.
        $porCobrar = self::porMoneda(
            DB::table('invoices')
                ->whereIn('status', ['issued', 'sent', 'partially_paid'])
                ->selectRaw(
                    'invoices.currency_code as moneda, SUM(invoices.total_amount - IFNULL('
                    .'(SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = invoices.id), 0'
                    .')) as importe',
                ),
            null, null, $filtros, 'invoices.client_organization_id',
        );

        $pagado = self::porMoneda(
            DB::table('payouts')
                ->where('status', 'confirmed')
                ->whereBetween('confirmed_at', [$desde, $hasta]),
            'payouts.currency_code', 'payouts.amount', $filtros, null,
        );

        // La fecha con la que se pide la tasa (`D-12`). El cierre del periodo,
        // y nunca el futuro: un periodo que termina el mes que viene no tiene
        // tasa publicada, y pedirla daria «sin tasa» en todas las lineas.
        $hoy = CarbonImmutable::now();
        $alCierre = ($hasta->greaterThan($hoy) ? $hoy : $hasta)->toDateString();

        $bloques = [
            ['clave' => 'facturado', 'titulo' => 'Facturado', 'lineas' => $facturado,
                'nota' => 'Comprobantes emitidos dentro del periodo. Borradores y anulados fuera.',
                'ruta' => 'facturas.index', 'flujo' => Consolidacion::INGRESO, 'fecha' => $alCierre],
            ['clave' => 'cobrado', 'titulo' => 'Cobrado', 'lineas' => $cobrado,
                'nota' => 'Cobros recibidos dentro del periodo, sea de qué factura sea.',
                'ruta' => 'facturas.index', 'flujo' => Consolidacion::INGRESO, 'fecha' => $alCierre],
            // Existencias a HOY, asi que su tasa es la de hoy y no la del
            // cierre del periodo: el bloque no se recorta por periodo, y su
            // conversion tampoco puede.
            ['clave' => 'por_cobrar', 'titulo' => 'Por cobrar hoy', 'lineas' => $porCobrar,
                'nota' => 'Emitido menos cobrado, a día de hoy. NO se recorta por periodo: '
                    .'una factura vieja sin pagar es justo la que hay que ver.',
                'ruta' => 'facturas.index', 'flujo' => Consolidacion::INGRESO,
                'fecha' => $hoy->toDateString()],
            // Lo unico que SALE, y por eso el unico que se convierte con el
            // lado de egresos (`DEC-350`).
            ['clave' => 'pagado', 'titulo' => 'Pagado a creadores', 'lineas' => $pagado,
                'nota' => 'Pagos confirmados dentro del periodo.',
                'ruta' => 'lotes.index', 'flujo' => Consolidacion::EGRESO, 'fecha' => $alCierre],
        ];

        $bloques = array_map(static function (array $bloque): array {
            $bloque['consolidado'] = Consolidacion::consolidar(
                $bloque['lineas'], $bloque['flujo'], $bloque['fecha'],
            );

            return $bloque;
        }, $bloques);

        $monedas = [];
        foreach ($bloques as $bloque) {
            foreach ($bloque['lineas'] as $linea) {
                $monedas[$linea['moneda']] = true;
            }
        }
        ksort($monedas);

        return [
            'bloques' => $bloques,
            'monedas' => array_keys($monedas),
            'ajustes' => Consolidacion::ajustes(),
        ];
    }

    /**
     * Suma una columna agrupando por moneda, con el recorte que le corresponde.
     *
     * De los cuatro filtros de la barra, aquí sólo significa **cliente**, y sólo
     * donde la tabla puede saberlo. Un pago a un creador no cuelga de ningún
     * cliente: con un cliente elegido, esa cifra devuelve **nada** en vez de
     * fingir que la recortó enseñando el total de todos. País, sociedad y
     * campaña no se aplican, y la cabecera del bloque lo dice.
     *
     * @return list<array{moneda: string, importe: float}>
     */
    private static function porMoneda(
        Builder $consulta,
        ?string $columnaMoneda,
        ?string $columnaImporte,
        FiltrosDeResumen $filtros,
        ?string $columnaCliente,
    ): array {
        if ($filtros->clienteId !== null) {
            if ($columnaCliente === null) {
                // Esta cifra no sabe de clientes. Con un cliente elegido, la
                // respuesta honrada es «no aplica», no el total sin filtrar.
                return [];
            }

            $consulta->where($columnaCliente, $filtros->clienteId);
        }

        if ($columnaMoneda !== null && $columnaImporte !== null) {
            $consulta->selectRaw(
                $columnaMoneda.' as moneda, SUM('.$columnaImporte.') as importe',
            );
        }

        $filas = [];

        foreach ($consulta->groupBy(DB::raw('moneda'))->get() as $fila) {
            $importe = (float) $fila->importe;

            if (abs($importe) < 0.00005) {
                continue;
            }

            $filas[] = ['moneda' => (string) $fila->moneda, 'importe' => round($importe, 2)];
        }

        usort($filas, static fn (array $a, array $b): int => $a['moneda'] <=> $b['moneda']);

        return $filas;
    }

    // --------------------------------------------------------- publicaciones

    /** Una publicación viva: verificada, o ya cumplida su permanencia. */
    public const PUBLICADAS_VIVAS = ['verified', 'fulfilled'];

    /**
     * Publicaciones y permanencia (`D-14`).
     *
     * ### Por qué esto existe si la «sección 7» está diferida
     *
     * El análisis del panel (§1.1) dejó fuera el rendimiento porque **no hay
     * fuente**: alcance, impresiones e interacciones necesitan las APIs de cada
     * red, y un número inventado sería peor que el hueco. Sigue siendo verdad.
     *
     * Pero de esa sección hay **tres cifras que sí se pueden medir hoy** con lo
     * que ya está guardado: cuántas publicaciones se registraron, cuántas se
     * verificaron y cuántas se cayeron. No dicen cómo funcionó el contenido;
     * dicen si el trato se cumplió, que es otra pregunta y también importa.
     * Enseñarlas no adelanta la sección 7: la deja más pequeña y más honesta.
     *
     * ### Las tres son FLUJO, y por la fecha que no cambia
     *
     * Registradas por `published_at`, verificadas por `verified_at`, caídas por
     * `removed_at`. Cada una cuenta en el periodo en que ocurrió y no se mueve
     * después, que es la misma regla que las invitaciones de `D-9`.
     *
     * ### El cumplimiento mira ventanas CERRADAS, no publicaciones
     *
     * De las ventanas de permanencia que se cerraron dentro del periodo
     * --cumplidas o caídas--, qué porcentaje se cumplió. Contarlo sobre todas
     * las publicaciones daría un número que baja solo cuando alguien publica,
     * porque una ventana abierta todavía no ha cumplido nada. Y sin ventanas
     * cerradas no hay porcentaje: hay un guion (`DEC-356`).
     *
     * @return array{
     *     tarjetas: list<array{titulo: string, valor: int, anterior: int, variacion: ?float,
     *                          sentido: string, tooltip: string, ruta: string}>,
     *     permanencia: array{cumplidas: int, caidas: int, porcentaje: ?float, vigilando: int}
     * }
     */
    public static function publicaciones(FiltrosDeResumen $filtros): array
    {
        return [
            'tarjetas' => [
                self::tarjeta(
                    'Publicaciones registradas',
                    'Posts que el creador registró con fecha de publicación dentro del periodo.',
                    'permanencia.bandeja',
                    $filtros,
                    static fn (FiltrosDeResumen $f): int => self::publicacionesEnPeriodo($f, 'p.published_at'),
                ),
                self::tarjeta(
                    'Publicaciones verificadas',
                    'Publicaciones que alguien comprobó dentro del periodo, con su captura.',
                    'permanencia.bandeja',
                    $filtros,
                    static fn (FiltrosDeResumen $f): int => self::publicacionesEnPeriodo($f, 'p.verified_at'),
                ),
                self::tarjeta(
                    'Publicaciones caídas',
                    'Publicaciones que se firmaron como retiradas dentro del periodo. '
                    .'Menos es mejor: aquí una subida es una mala noticia.',
                    'permanencia.bandeja',
                    $filtros,
                    static fn (FiltrosDeResumen $f): int => self::publicacionesEnPeriodo($f, 'p.removed_at'),
                ),
            ],
            'permanencia' => self::permanencia($filtros),
        ];
    }

    /**
     * Cumplimiento de permanencia sobre las ventanas CERRADAS del periodo.
     *
     * @return array{cumplidas: int, caidas: int, porcentaje: ?float, vigilando: int}
     */
    private static function permanencia(FiltrosDeResumen $filtros): array
    {
        $vacio = ['cumplidas' => 0, 'caidas' => 0, 'porcentaje' => null, 'vigilando' => 0];

        if (!Schema::hasTable('publications') || !Schema::hasColumn('publications', 'fulfilled_at')) {
            return $vacio;
        }

        $cumplidas = self::publicacionesEnPeriodo($filtros, 'p.fulfilled_at');
        $caidas = self::publicacionesEnPeriodo($filtros, 'p.removed_at');
        $cerradas = $cumplidas + $caidas;

        // Existencias, no flujo: cuántas se están vigilando HOY. No se recorta
        // por periodo --una ventana abierta lo está hoy, no «en agosto»-- igual
        // que «por cobrar» en el bloque financiero.
        $vigilando = (int) self::recortar(
            self::basePublicaciones()
                ->where('p.status', 'verified')
                ->whereNotNull('p.permanence_until')
                ->whereDate('p.permanence_until', '>=', CarbonImmutable::now()->toDateString()),
            $filtros,
        )->count();

        return [
            'cumplidas' => $cumplidas,
            'caidas' => $caidas,
            // Sin ventanas cerradas NO hay porcentaje. Un 100 % con cero de cero
            // se lee como «todo perfecto» donde lo que pasa es que no ha
            // terminado nada (`DEC-356`).
            'porcentaje' => $cerradas === 0 ? null : round($cumplidas / $cerradas * 100, 1),
            'vigilando' => $vigilando,
        ];
    }

    /** Publicaciones cuya `$columna` cae dentro del periodo, con los filtros puestos. */
    private static function publicacionesEnPeriodo(FiltrosDeResumen $filtros, string $columna): int
    {
        if (!Schema::hasTable('publications')) {
            return 0;
        }

        if ($columna === 'p.fulfilled_at' && !Schema::hasColumn('publications', 'fulfilled_at')) {
            return 0;
        }

        return (int) self::recortar(
            self::basePublicaciones()->whereBetween($columna, [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ]),
            $filtros,
        )->count();
    }

    /**
     * Publicaciones con su campaña al lado, para que los filtros signifiquen algo.
     *
     * Una publicación cuelga de un entregable, el entregable de una
     * participación y la participación de una campaña: sin recorrer las tres,
     * el filtro de cliente o de país no tendría de dónde agarrarse.
     */
    private static function basePublicaciones(): Builder
    {
        return DB::table('publications as p')
            ->join('deliverables as d', 'd.id', '=', 'p.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns', 'campaigns.id', '=', 'cc.campaign_id');
    }

    // ----------------------------------------------------------------- margen

    /**
     * Los estados en los que una campaña ya compromete dinero (`D-13`).
     *
     * Un borrador y una pendiente de aprobar todavía no comprometen nada: su
     * `revenue_amount` es una intención. Una cancelada tampoco, y además su
     * ingreso no va a llegar. Meterlas en un agregado infla el ingreso sin que
     * nadie lo vea --en la pantalla de Rentabilidad se ven fila a fila con su
     * estado al lado, que es otra cosa--.
     */
    public const COMPROMETIDAS = ['approved', 'recruiting', 'in_progress', 'in_review', 'completed'];

    /** Devengos que no cuentan como costo: los anulados (`Costos::DEVENGOS_MUERTOS`). */
    private const DEVENGOS_MUERTOS = ['void'];

    /**
     * El margen del periodo (`D-13`), detrás de `campaign.view_margin`.
     *
     * ### La misma resta de `9.10`, y por eso hay una prueba que lo exige
     *
     * **ingreso − costo de creadores − gasto operativo**, exactamente como
     * `Rentabilidad`. Pero `Rentabilidad` vive en `Finance` y esto vive en
     * `Core`, que sólo puede depender de `Framework` y `Shared`: llamarla desde
     * aquí rompería el grafo, y `deptrac` lo diría. Así que la resta está
     * escrita **dos veces**, que es una deuda real y no un descuido
     * (`DEC-353`).
     *
     * Lo que impide que las dos definiciones se separen no es un comentario: es
     * `MargenTest::test_el_panel_y_rentabilidad_dan_el_mismo_numero`, que las
     * pone a las dos delante de los mismos datos y compara. El día que alguien
     * cambie una, esa prueba lo dice.
     *
     * ### Lo que queda fuera, y se cuenta
     *
     * Un **canje** no entra (`DEC-184`): su ingreso es cero por decisión, así
     * que su margen siempre sale negativo y hundiría la media por un motivo
     * deliberado. Una campaña con **gastos en otra moneda** tampoco: su margen
     * estaría incompleto, y sumar un número incompleto lo vuelve invisible. Las
     * dos se cuentan en `fuera` y la pantalla dice cuántas son.
     *
     * ### El consolidado convierte las TRES cifras, no el neto
     *
     * Y cada una por su lado (`DEC-351`): el ingreso al tipo de lo que entra,
     * los costos al de lo que sale. Convertir el margen ya restado usaría un
     * solo lado para dos flujos opuestos, que es justo lo que `D-12` vino a
     * evitar.
     *
     * @return array{
     *     lineas: list<array{moneda: string, ingreso: float, creadores: float, gasto: float, margen: float}>,
     *     fuera: int,
     *     consolidado: array{moneda: string, ingreso: ?float, costos: ?float, margen: ?float,
     *                        parcial: bool, fecha: ?string},
     *     porcentaje: ?float,
     *     veto: ?string
     * }
     */
    public static function margen(FiltrosDeResumen $filtros): array
    {
        $vacio = [
            'lineas' => [], 'fuera' => 0,
            'consolidado' => [
                'moneda' => Consolidacion::ajustes()['moneda'],
                'ingreso' => null, 'costos' => null, 'margen' => null,
                'parcial' => false, 'fecha' => null,
            ],
            'porcentaje' => null,
            'veto' => null,
        ];

        if (!Schema::hasTable('campaigns')) {
            return $vacio;
        }

        $consulta = DB::table('campaigns')
            ->whereIn('campaigns.status', self::COMPROMETIDAS)
            ->where('campaigns.starts_on', '<=', $filtros->hasta->toDateString())
            ->whereRaw('IFNULL(campaigns.ends_on, ?) >= ?', [
                $filtros->hasta->toDateString(), $filtros->desde->toDateString(),
            ]);

        $campanas = self::recortar($consulta, $filtros)->get([
            'campaigns.id', 'campaigns.currency_code', 'campaigns.revenue_amount', 'campaigns.is_gratis',
        ]);

        if ($campanas->isEmpty()) {
            return $vacio;
        }

        $ids = [];
        foreach ($campanas as $campana) {
            $ids[] = (int) $campana->id;
        }

        $creadores = self::costoDeCreadores($ids);
        $gastos = self::gastosDeCampana($ids);

        $porMoneda = [];
        $fuera = 0;

        foreach ($campanas as $campana) {
            $id = (int) $campana->id;
            $moneda = mb_strtoupper((string) $campana->currency_code);
            $mios = $creadores[$id] ?? [];
            $suyos = $gastos[$id] ?? [];

            // Una campana en soles que pago un envio en dolares tiene el margen
            // INCOMPLETO en soles. Se queda fuera y se cuenta.
            $otras = array_diff(
                array_merge(array_keys($mios), array_keys($suyos)),
                [$moneda],
            );

            if ((bool) $campana->is_gratis || $otras !== []) {
                $fuera++;

                continue;
            }

            $porMoneda[$moneda] ??= ['ingreso' => 0.0, 'creadores' => 0.0, 'gasto' => 0.0];
            $porMoneda[$moneda]['ingreso'] += (float) $campana->revenue_amount;
            $porMoneda[$moneda]['creadores'] += (float) ($mios[$moneda] ?? 0.0);
            $porMoneda[$moneda]['gasto'] += (float) ($suyos[$moneda] ?? 0.0);
        }

        ksort($porMoneda);

        $lineas = [];
        $ingresos = [];
        $costos = [];

        foreach ($porMoneda as $moneda => $cifras) {
            $margen = $cifras['ingreso'] - $cifras['creadores'] - $cifras['gasto'];

            $lineas[] = [
                'moneda' => $moneda,
                'ingreso' => round($cifras['ingreso'], 2),
                'creadores' => round($cifras['creadores'], 2),
                'gasto' => round($cifras['gasto'], 2),
                'margen' => round($margen, 2),
            ];

            $ingresos[] = ['moneda' => $moneda, 'importe' => round($cifras['ingreso'], 2)];
            $costos[] = [
                'moneda' => $moneda,
                'importe' => round($cifras['creadores'] + $cifras['gasto'], 2),
            ];
        }

        $fecha = ($filtros->hasta->greaterThan(CarbonImmutable::now())
            ? CarbonImmutable::now() : $filtros->hasta)->toDateString();

        $entra = Consolidacion::consolidar($ingresos, Consolidacion::INGRESO, $fecha);
        $sale = Consolidacion::consolidar($costos, Consolidacion::EGRESO, $fecha);

        $parcial = $entra['parcial'] || $sale['parcial'];

        $consolidado = [
            'moneda' => $entra['moneda'],
            'ingreso' => $entra['total'],
            'costos' => $sale['total'],
            'margen' => $entra['total'] === null ? null : $entra['total'] - ($sale['total'] ?? 0.0),
            'parcial' => $parcial,
            'fecha' => $entra['fecha'] ?? $sale['fecha'],
        ];

        // El porcentaje sólo cuando el consolidado está COMPLETO. Con algo
        // fuera --una moneda sin tasa, un canje, una campaña con gastos
        // ajenos-- el numerador y el denominador no hablan de lo mismo, y un
        // porcentaje así se usa para decidir igual: ahí está el daño (`9.10`).
        $veto = null;

        if ($parcial) {
            $veto = 'No hay porcentaje: falta convertir alguna moneda, así que el total está incompleto.';
        } elseif ($fuera > 0) {
            $veto = sprintf(
                'No hay porcentaje: %d campaña%s queda%s fuera del total —canje o gastos en otra '
                .'moneda— y el porcentaje hablaría de menos campañas que la cifra.',
                $fuera, $fuera === 1 ? '' : 's', $fuera === 1 ? '' : 'n',
            );
        } elseif (($consolidado['ingreso'] ?? 0.0) <= 0.0) {
            $veto = 'No hay porcentaje: sin ingreso no hay sobre qué calcularlo.';
        }

        return [
            'lineas' => $lineas,
            'fuera' => $fuera,
            'consolidado' => $consolidado,
            'porcentaje' => $veto === null && $consolidado['margen'] !== null
                ? round($consolidado['margen'] / (float) $consolidado['ingreso'] * 100, 1)
                : null,
            'veto' => $veto,
        ];
    }

    /**
     * Lo devengado a creadores por campaña y moneda.
     *
     * Misma consulta que `Rentabilidad::creadoresDe()`. Ver el bloque de
     * `margen()` sobre por qué está escrita dos veces.
     *
     * @param list<int> $ids
     * @return array<int, array<string, float>>
     */
    private static function costoDeCreadores(array $ids): array
    {
        $filas = DB::table('ledger_entries as le')
            ->join('campaign_creators as cc', 'cc.id', '=', 'le.campaign_creator_id')
            ->whereIn('cc.campaign_id', $ids)
            ->where('le.entry_type', 'earning')
            ->whereNotIn('le.status', self::DEVENGOS_MUERTOS)
            ->groupBy('cc.campaign_id', 'le.currency_code')
            ->get(['cc.campaign_id', 'le.currency_code', DB::raw('SUM(le.amount) as total')]);

        $porCampana = [];

        foreach ($filas as $fila) {
            $porCampana[(int) $fila->campaign_id][mb_strtoupper((string) $fila->currency_code)]
                = (float) $fila->total;
        }

        return $porCampana;
    }

    /**
     * El gasto operativo por campaña y moneda, sin los anulados.
     *
     * @param list<int> $ids
     * @return array<int, array<string, float>>
     */
    private static function gastosDeCampana(array $ids): array
    {
        $filas = DB::table('campaign_costs')
            ->whereIn('campaign_id', $ids)
            ->whereNull('voided_at')
            ->groupBy('campaign_id', 'currency_code')
            ->get(['campaign_id', 'currency_code', DB::raw('SUM(amount) as total')]);

        $porCampana = [];

        foreach ($filas as $fila) {
            $porCampana[(int) $fila->campaign_id][mb_strtoupper((string) $fila->currency_code)]
                = (float) $fila->total;
        }

        return $porCampana;
    }

    // -------------------------------------------------------------- creadores

    /** Los seis estados de un creador, con su nombre en pantalla (`D-9`). */
    public const CREADORES = [
        'pending' => 'Pendientes de aprobar',
        'active' => 'Activos',
        'suspended' => 'Suspendidos',
        'inactive' => 'Inactivos',
        'rejected' => 'Rechazados',
        'blacklisted' => 'Vetados',
    ];

    /**
     * Los indicadores de la red de creadores (`D-9`).
     *
     * Los tres son **flujo**, por la misma razón que en comercial: se cuentan
     * por una fecha que no cambia. Y una invitación cuenta en el periodo en que
     * se **envió**, no en el que se contestó: si contara al contestar, la tasa
     * de aceptación de un periodo cambiaría según el día en que se mire.
     *
     * @return list<array{titulo: string, valor: int, anterior: int, variacion: ?float,
     *                    sentido: string, tooltip: string, ruta: string}>
     */
    public static function creadores(FiltrosDeResumen $filtros): array
    {
        return [
            self::tarjeta(
                'Creadores nuevos',
                'Creadores dados de alta dentro del periodo, sea cual sea su estado hoy.',
                'creadores.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::creadoresNuevos($f),
            ),
            self::tarjeta(
                'Invitaciones enviadas',
                'Invitaciones a participar enviadas dentro del periodo.',
                'creadores.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::invitaciones($f),
            ),
            self::tarjeta(
                'Invitaciones aceptadas',
                'De las enviadas EN EL PERIODO, las que se aceptaron. Una invitación de marzo '
                .'aceptada en abril cuenta en marzo: así la tasa de un periodo no cambia según '
                .'cuándo se mire.',
                'creadores.index',
                $filtros,
                static fn (FiltrosDeResumen $f): int => self::invitaciones($f, 'accepted'),
            ),
        ];
    }

    /**
     * La red por dentro: en qué estado están y cuántos tienen identidad verificada.
     *
     * ### Lo que NO está aquí, y por qué
     *
     * - **El reparto por rango de seguidores** necesita que los rangos sean
     *   configurables (`DEC-190`) y que se decida qué instantánea vale, porque
     *   `social_account_snapshots` guarda una serie. Es una iteración con su
     *   pantalla de configuración, no una consulta.
     * - **El «nivel de desempeño»** no existe: no hay tabla de evaluación.
     *   Componerlo con campañas completadas, puntualidad y aprobación sería
     *   **definir un indicador nuevo**, no leer uno, y eso lo decide el negocio.
     *
     * Decirlo en el código y en la pantalla evita que el hueco se lea como un
     * fallo, que es la mitad de `DEC-190` que se olvida.
     *
     * @return array{filas: list<array{clave: string, nombre: string, cantidad: int}>,
     *               total: int, activos: int, verificados: int, aceptacion: ?float}
     */
    public static function redDeCreadores(FiltrosDeResumen $filtros): array
    {
        $cuentas = [];

        foreach (DB::table('creators')
            ->whereNull('anonymized_at')
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) as cuantos'))
            ->get() as $fila) {
            $cuentas[(string) $fila->status] = (int) $fila->cuantos;
        }

        $filas = [];
        $total = 0;

        foreach (self::CREADORES as $clave => $nombre) {
            $cantidad = (int) ($cuentas[$clave] ?? 0);
            $total += $cantidad;
            $filas[] = ['clave' => $clave, 'nombre' => $nombre, 'cantidad' => $cantidad];
        }

        $enviadas = self::invitaciones($filtros);
        $aceptadas = self::invitaciones($filtros, 'accepted');

        return [
            'filas' => $filas,
            'total' => $total,
            'activos' => (int) ($cuentas['active'] ?? 0),
            // La verificación de identidad es lo que habilita cobrar, así que
            // se mira sobre los ACTIVOS: un rechazado sin verificar no falta.
            'verificados' => DB::table('creators')
                ->whereNull('anonymized_at')
                ->where('status', 'active')
                ->whereNotNull('identity_verified_at')
                ->count(),
            'aceptacion' => $enviadas === 0 ? null : round($aceptadas / $enviadas * 100, 1),
        ];
    }

    private static function creadoresNuevos(FiltrosDeResumen $filtros): int
    {
        $consulta = DB::table('creators')
            ->whereNull('anonymized_at')
            ->whereBetween('created_at', [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ]);

        if ($filtros->paisId !== null) {
            $consulta->where('country_id', $filtros->paisId);
        }

        return $consulta->count();
    }

    /**
     * Invitaciones enviadas en el periodo, opcionalmente sólo las de una respuesta.
     *
     * Se cuenta por `sent_at` SIEMPRE, también al filtrar por respuesta: es lo
     * que hace que «aceptadas ÷ enviadas» sea una tasa de ese periodo y no una
     * mezcla de dos.
     */
    private static function invitaciones(FiltrosDeResumen $filtros, ?string $respuesta = null): int
    {
        $consulta = DB::table('invitations')
            ->whereBetween('invitations.sent_at', [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ]);

        if ($respuesta !== null) {
            $consulta->where('invitations.response', $respuesta);
        }

        // El recorte por campaña, cliente o país llega por la participación.
        if ($filtros->clienteId !== null || $filtros->campanaId !== null || $filtros->paisId !== null) {
            $consulta->join('campaign_creators as cc', 'cc.id', '=', 'invitations.campaign_creator_id')
                ->join('campaigns', 'campaigns.id', '=', 'cc.campaign_id');

            self::recortar($consulta, $filtros);
        }

        return $consulta->count();
    }

    // -------------------------------------------------------------- actividad

    /** Cuántas líneas de actividad se enseñan. Es una portada, no la bitácora. */
    public const ACTIVIDAD = 12;

    /**
     * Lo último que ha pasado, de la bitácora (`D-10`).
     *
     * ### Detrás de `audit.view`, y sin filtrar por acción
     *
     * La bitácora lo guarda TODO: quién tocó una tarifa, quién aprobó un pago,
     * quién cambió los datos fiscales de un creador. Repartirla por acción
     * —«esto sí lo puede ver un revisor de contenido, esto no»— sería mantener
     * un segundo mapa de permisos en paralelo al de verdad, y el día que se
     * añada una acción nueva **entraría sin que nadie decidiera en qué lado
     * está**. Así que este bloque cuelga del mismo permiso que la pantalla de la
     * bitácora, `audit.view`, y quien no lo tiene no lo ve ni lo consulta.
     *
     * ### Los cambios NO se pintan aquí
     *
     * `audit_logs.changes` lleva el antes y el después de cada campo, y en él
     * viaja información que no tiene por qué salir en una portada —un correo,
     * un domicilio, un importe—. La portada dice **qué pasó, quién y cuándo**, y
     * el detalle está a un clic en la bitácora, que es donde ese contenido ya
     * tiene su pantalla y su permiso. Enseñar menos aquí no es prudencia
     * decorativa: `changes` está redactado por `Bitacora::redactar()` para la
     * bitácora, no para un resumen.
     *
     * ### Se recorta por periodo y por nada más
     *
     * Ni cliente ni país ni campaña: una entrada de bitácora no cuelga de una
     * campaña, cuelga de una entidad cualquiera. Fingir que el filtro se aplica
     * sería peor que no aplicarlo (`DEC-337`).
     *
     * @return list<array{accion: string, quien: string, entidad: string, cuando: string}>
     */
    public static function actividad(FiltrosDeResumen $filtros): array
    {
        if (!Schema::hasTable('audit_logs')) {
            return [];
        }

        $filas = [];

        foreach (DB::table('audit_logs')
            ->whereBetween('occurred_at', [
                $filtros->desde->startOfDay(), $filtros->hasta->endOfDay(),
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVIDAD)
            ->get(['action', 'actor_label', 'entity_type', 'entity_id', 'occurred_at']) as $fila) {
            $filas[] = [
                'accion' => (string) $fila->action,
                // `actor_label` y no el id: la bitacora congela COMO SE LLAMABA
                // quien lo hizo, para que borrar la cuenta no borre el rastro.
                // Vacio significa que lo hizo el sistema, y se dice.
                'quien' => trim((string) ($fila->actor_label ?? '')) === ''
                    ? 'el sistema'
                    : (string) $fila->actor_label,
                'entidad' => $fila->entity_id === null
                    ? (string) $fila->entity_type
                    : $fila->entity_type.' #'.$fila->entity_id,
                'cuando' => CarbonImmutable::parse((string) $fila->occurred_at)->format('d/m H:i'),
            ];
        }

        return $filas;
    }

    private static function variacion(int $valor, int $anterior): ?float
    {
        if ($anterior === 0) {
            return null;
        }

        return round((($valor - $anterior) / $anterior) * 100, 1);
    }

    private static function sentido(int $valor, int $anterior): string
    {
        return match (true) {
            $valor > $anterior => 'sube',
            $valor < $anterior => 'baja',
            default => 'igual',
        };
    }
}
