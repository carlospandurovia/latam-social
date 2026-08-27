<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Campaign\Http\Requests\GuardarCampanaRequest;
use App\Modules\Campaign\Http\Requests\GuardarMercadoRequest;
use App\Modules\Campaign\Http\Requests\GuardarRequisitoRequest;
use App\Modules\Campaign\Services\Campanas;
use App\Modules\Campaign\Services\EstadosDeCampana;
use App\Modules\Campaign\Services\Mercados;
use App\Shared\Audit\Bitacora;
use App\Shared\Auth\Permisos;
use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Campañas: alta, ficha y movimiento de estado (7.1).
 *
 * ### Lo que esta pantalla existe para impedir
 *
 * Dos cosas, y las dos son de dinero:
 *
 * 1. **Que una campaña salga de borrador sin saber quién la factura.**
 *    `BR-LE-004` lo dice literal: *nunca se asigna una entidad por defecto ni se
 *    continúa en silencio*. Si ningún país está cubierto, se bloquea **con el
 *    motivo y con qué hacer**, no con un 500 ni con una sociedad inventada.
 * 2. **Que alguien salte estados.** `ck_camp_status` admite ocho valores y no
 *    dice nada de cómo se pasa de uno a otro; eso vive en `EstadosDeCampana`.
 *
 * ### El permiso de aprobar es OTRO
 *
 * Aprobar fija el ingreso comprometido y congela la sociedad emisora. Lo firma
 * finanzas y no quien montó la campaña —la misma separación que `DEC-044`
 * impone en la base para perfiles fiscales y medios de pago—, y por eso el
 * permiso sale del **grafo**, no de la ruta: una ruta con un permiso fijo
 * obligaría a partir la acción en dos y a acordarse de las dos.
 */
final class CampanasController
{
    public function index(Request $peticion): View
    {
        $estado = (string) $peticion->query('estado', '');

        $consulta = DB::table('campaigns as c')
            ->join('client_organizations as co', 'co.id', '=', 'c.client_organization_id')
            ->join('client_brands as cb', 'cb.id', '=', 'c.client_brand_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'c.billing_legal_entity_id')
            ->orderByDesc('c.starts_on');

        if (isset(EstadosDeCampana::NOMBRES[$estado])) {
            $consulta->where('c.status', $estado);
        }

        return view('campanas.index', [
            'campanas' => $consulta->get([
                'c.uuid', 'c.code', 'c.name', 'c.status', 'c.starts_on', 'c.ends_on',
                'c.revenue_amount', 'c.currency_code',
                // 7.7: el listado enlaza al SEGUIMIENTO cuando la campana ya
                // esta confirmada, y a la ficha cuando todavia se esta montando.
                // Es la diferencia entre «como va» y «que es», y quien entra
                // busca una cosa u otra segun el momento.
                'c.confirmed_at',
                'co.commercial_name as cliente', 'cb.name as marca', 'le.code as sociedad',
            ]),
            'estados' => EstadosDeCampana::NOMBRES,
            'estado' => $estado,
            // Cuantas hay sin sociedad: son las que no van a poder salir de
            // borrador, y verlo aqui evita descubrirlo una por una.
            'sinSociedad' => DB::table('campaigns')->whereNull('billing_legal_entity_id')->count(),
        ]);
    }

    public function show(string $uuid): View
    {
        $campana = $this->campana($uuid);

        return view('campanas.show', [
            'campana' => $campana,
            'estados' => EstadosDeCampana::NOMBRES,
            'objetivos' => Campanas::OBJETIVOS,
            // Solo las transiciones que ESTE usuario puede hacer. Ensenar un
            // boton que va a dar 403 es peor que no ensenarlo.
            'transiciones' => $this->transicionesDisponibles((string) $campana->status),
            // Las DOS respuestas: la guardada, que es la que manda (`BR-LE-001`),
            // y la que resolveria la cobertura de hoy. La pantalla ensena la
            // primera y avisa si difieren, en vez de contar la segunda bajo el
            // rotulo de la primera, que es lo que hacia hasta `T-58`.
            'sociedad' => Campanas::quienFacturaEsta($campana),
            'requisitos' => Campanas::requisitos((int) $campana->id),
            'mercados' => Mercados::de((int) $campana->id),
            'paises' => Mercados::paisesDisponibles((int) $campana->id),
            // Cuales tienen brief PROPIO. Dos mercados con el mismo brief se ven
            // igual sin poder saber si es que heredan el general o que alguien
            // escribio lo mismo dos veces, y eso cambia que pasa al editar el
            // general (`N-03`: reemplaza, no mezcla).
            'conBriefPropio' => Mercados::conBriefPropio((int) $campana->id),
            'formatos' => DB::table('content_formats as f')
                ->leftJoin('platforms as p', 'p.id', '=', 'f.platform_id')
                ->where('f.is_active', 1)
                ->orderBy('p.name')->orderBy('f.code')
                // `default_permanence_days` viaja a la pantalla para que el
                // formulario proponga la permanencia del formato en vez de un 30
                // fijo: cada red tiene la suya y teclearla a mano es teclearla mal.
                ->get(['f.id', 'f.code', 'f.default_permanence_days', 'p.name as red']),
            // Lo que impide salir de borrador, enseñado ANTES de intentarlo:
            // descubrirlo al pulsar el boton es enterarse tarde.
            'faltan' => Campanas::loQueFaltaParaSalirDeBorrador($campana),
            // Si el brief todavia se toca. Se decide AQUI y no en la plantilla:
            // la lista de estados iniciales vive en `EstadosDeCampana` y una
            // plantilla que la repita hay que acordarse de tocarla el dia que se
            // anada un estado. Es la misma comprobacion que veta el `POST`.
            'editable' => self::vetoPorNoEditable($campana) === null,
        ]);
    }

    public function create(): View
    {
        return view('campanas.form', $this->datosDelFormulario());
    }

    public function store(GuardarCampanaRequest $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        // La sociedad se resuelve a `starts_on`, no a hoy: `BR-LE-003` dice «en
        // la fecha de la operacion», y para una campana esa fecha es cuando
        // empieza a prestarse el servicio.
        $cobertura = Campanas::quienFactura(
            (int) $datos['client_organization_id'],
            (string) $datos['starts_on'],
        );

        $uuid = null;
        $codigo = null;
        $probados = [];

        // `$datos` va POR REFERENCIA. Sin el `&`, el `code` que calcula el
        // reintento se queda dentro del cierre y el mensaje de exito de abajo
        // lee una clave que no existe. Salio a la primera ejecucion de las
        // pruebas, que es exactamente para lo que estan.
        Choque::reintentar('uq_camp_code', function () use (
            $peticion, &$datos, $cobertura, &$uuid, &$codigo, &$probados
        ): void {
            $codigo = Campanas::codigoLibre(
                $peticion->nombreDelCliente(),
                (int) substr((string) $datos['starts_on'], 0, 4),
                evitando: $probados,
            );
            $probados[] = $codigo;
            $datos['code'] = $codigo;

            $uuid = Campanas::crear(
                $datos,
                $cobertura->hay() ? (int) $cobertura->entidad->id : null,
                (int) Auth::id(),
            );
        });

        Bitacora::registrar(
            accion: 'campaign.created',
            tipoEntidad: 'campaign',
            idEntidad: (int) DB::table('campaigns')->where('uuid', $uuid)->value('id'),
            cambios: ['campana' => ['antes' => null, 'despues' => $datos['name']]],
        );

        $mensaje = "Campana «{$datos['name']}» creada como borrador, con codigo {$codigo}.";

        return redirect()->route('campanas.show', $uuid)->with(
            $cobertura->hay() ? 'exito' : 'aviso',
            $cobertura->hay()
                ? $mensaje." La factura la emitira {$cobertura->entidad->code}."
                : $mensaje.' '.$cobertura->explicacion.' Hasta entonces se queda en borrador.',
        );
    }

    public function edit(string $uuid): View
    {
        $campana = $this->campana($uuid);

        if (!in_array((string) $campana->status, EstadosDeCampana::INICIALES, true)) {
            // No es un 403: el usuario PUEDE gestionar campanas. Lo que no se
            // puede es editar esta, y eso se dice.
            abort(409, 'Una campana confirmada no se edita: sus datos ya se comprometieron con el cliente.');
        }

        // El formulario de edicion SI puede decir quien va a facturar: la campana
        // ya tiene cliente y fecha. El de alta no --no hay ni uno ni otra-- y por
        // eso no se inventa una respuesta ahi: ver `datosDelFormulario()`.
        return view('campanas.form', $this->datosDelFormulario() + [
            'campana' => $campana,
            'sociedad' => Campanas::quienFacturaEsta($campana),
        ]);
    }

    public function update(GuardarCampanaRequest $peticion, string $uuid): RedirectResponse
    {
        $campana = $this->campana($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        $cobertura = Campanas::quienFactura(
            (int) $datos['client_organization_id'],
            (string) $datos['starts_on'],
        );

        $cambios = [];
        foreach ($datos as $campo => $valor) {
            if ((string) ($campana->{$campo} ?? '') !== (string) ($valor ?? '')) {
                $cambios[$campo] = ['antes' => $campana->{$campo} ?? null, 'despues' => $valor];
            }
        }

        DB::table('campaigns')->where('id', $campana->id)->update($datos + [
            // Cambiar la fecha de inicio puede cambiar QUIEN factura. Se
            // recalcula en vez de arrastrar la de antes: si no, mover la fecha
            // dejaria la campana con la sociedad de la fecha vieja, que es
            // exactamente el «deducirlo de la configuracion vigente» que
            // `BR-LE-001` prohibe, pero congelado al reves.
            'billing_legal_entity_id' => $cobertura->hay() ? (int) $cobertura->entidad->id : null,
            'updated_at' => now(),
        ]);

        if ($cambios !== []) {
            Bitacora::registrar(
                accion: 'campaign.updated',
                tipoEntidad: 'campaign',
                idEntidad: (int) $campana->id,
                cambios: $cambios,
            );
        }

        return redirect()->route('campanas.show', $uuid)->with('exito', 'Campana actualizada.');
    }

    /**
     * Mueve la campaña de estado.
     *
     * Una sola acción para las ocho transiciones. El permiso lo dice el grafo,
     * no la ruta: partirlo en una acción por transición obligaría a repetir el
     * permiso en cada una y a acordarse de todas al añadir un estado.
     */
    public function transicionar(Request $peticion, string $uuid): RedirectResponse
    {
        $campana = $this->campana($uuid);
        $desde = (string) $campana->status;
        $hasta = (string) $peticion->input('estado', '');

        if (($aviso = EstadosDeCampana::veto($desde, $hasta)) !== null) {
            return back()->with('aviso', $aviso);
        }

        $permiso = (string) EstadosDeCampana::permiso($desde, $hasta);

        if (!Permisos::tiene((int) Auth::id(), $permiso)) {
            abort(403, "Esta transicion exige el permiso `{$permiso}`.");
        }

        // Salir de borrador con algo a medias lo rechazan los `CHECK` del esquema
        // con un 45000. Se veta ANTES para poder decir QUE falta y donde se
        // arregla, en vez de traducir un error del motor.
        //
        // Se dicen TODOS los motivos de una vez, no el primero: si la campana
        // no tiene requisitos y ademas nadie decidio el precio, enterarse de lo
        // segundo despues de arreglar lo primero es una visita mas para nada.
        // Mismo criterio que la comprobacion previa de las migraciones.
        if (!in_array($hasta, EstadosDeCampana::INICIALES, true)) {
            $faltan = Campanas::loQueFaltaParaSalirDeBorrador($campana);

            if ($faltan !== []) {
                return back()->with('aviso', sprintf(
                    'Esta campana no puede pasar a «%s» todavia (BR-CAMPAIGN-004). Falta: %s.',
                    EstadosDeCampana::NOMBRES[$hasta],
                    implode('; ', $faltan),
                ));
            }
        }

        Campanas::transicionar($campana, $hasta);

        Bitacora::registrar(
            accion: 'campaign.status_changed',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['status' => ['antes' => $desde, 'despues' => $hasta]],
        );

        return redirect()->route('campanas.show', $uuid)->with('exito', sprintf(
            'Campana movida a «%s».%s',
            EstadosDeCampana::NOMBRES[$hasta],
            $campana->confirmed_at === null && in_array($hasta, EstadosDeCampana::confirmados(), true)
                ? ' A partir de ahora la sociedad que la factura ya no se puede cambiar (BR-LE-002).'
                : '',
        ));
    }

    /**
     * Añade un requisito al brief.
     *
     * Sólo mientras la campaña sea editable. Una vez confirmada, lo que hay que
     * entregar es lo que se le prometió al cliente y —cuando existan
     * participaciones— lo que los creadores aceptaron: cambiarlo exige una
     * enmienda (`BR-CAMPAIGN-003`), no un formulario.
     */
    public function anadirRequisito(GuardarRequisitoRequest $peticion, string $uuid): RedirectResponse
    {
        $campana = $this->campana($uuid);

        if (($aviso = self::vetoPorNoEditable($campana)) !== null) {
            return back()->with('aviso', $aviso);
        }

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        // El mismo formato dos veces lo rechaza la base con un 1062, y hay DOS
        // indices que lo hacen:
        //
        // | Indice | Cubre |
        // |---|---|
        // | `uq_creq_general` | el requisito general, via `general_gate` |
        // | `uq_creq_market`  | el de un mercado concreto (7.3) |
        //
        // Se traduce, no se absorbe (`DEC-087`): repetir un formato no es un
        // valor que el sistema pueda recalcular --es que el operador queria
        // EDITAR la fila que ya existe--.
        //
        // La primera version solo miraba `uq_creq_general`, porque cuando se
        // escribio los mercados no existian. `7.3` los anadio, el formulario
        // empezo a mandar `campaign_market_id`, y anadir dos veces el mismo
        // formato a un mercado paso a dar un **500 con la traza entera**. No
        // fallaba en pruebas: la suite SQL comprobaba que la base lo rechaza
        // --y lo rechaza-- pero nadie comprobaba que la PANTALLA lo explique.
        try {
            DB::table('campaign_requirements')->insert($datos + [
                'campaign_id' => $campana->id,
                // `campaign_market_id` viene en `$datos` desde 7.3, y vacio
                // significa «todos los mercados» (docs 2.3 §9, `N-03`). El `+`
                // no pisa lo que ya esta: esto es el valor por omision para
                // cuando el formulario no lo manda.
                'campaign_market_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $general = Choque::esDe($e, 'uq_creq_general');
            $mercado = Choque::esDe($e, 'uq_creq_market');

            if (!$general && !$mercado) {
                throw $e;
            }

            return back()->withInput()->with('aviso', $mercado && ($datos['campaign_market_id'] ?? null) !== null
                ? 'Ese formato ya esta en el brief de ese mercado. Edite la fila que hay en vez de '
                  .'anadir otra: dos filas del mismo formato serian dos cantidades para la misma cosa.'
                : 'Ese formato ya esta en el brief general. Edite la fila que hay en vez de anadir '
                  .'otra: dos filas del mismo formato serian dos cantidades para la misma cosa.');
        }

        Bitacora::registrar(
            accion: 'campaign.requirement_added',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['requisito' => ['antes' => null, 'despues' => $datos]],
        );

        return redirect()->route('campanas.show', $uuid)->with('exito', 'Requisito anadido al brief.');
    }

    /** Quita un requisito del brief. */
    public function quitarRequisito(string $uuid, int $requisito): RedirectResponse
    {
        $campana = $this->campana($uuid);

        if (($aviso = self::vetoPorNoEditable($campana)) !== null) {
            return back()->with('aviso', $aviso);
        }

        $fila = DB::table('campaign_requirements')
            ->where('id', $requisito)->where('campaign_id', $campana->id)->first();

        if ($fila === null) {
            // Y no un 404 generico: el requisito puede existir y ser de OTRA
            // campana, y eso es lo que hay que impedir. Comprobar el par
            // (campana, requisito) y no solo el id es la misma leccion que
            // «no se edita la marca de otro cliente por la URL».
            throw new NotFoundHttpException('Ese requisito no es de esta campana.');
        }

        DB::table('campaign_requirements')->where('id', $requisito)->delete();

        Bitacora::registrar(
            accion: 'campaign.requirement_removed',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['requisito' => ['antes' => (array) $fila, 'despues' => null]],
        );

        return redirect()->route('campanas.show', $uuid)->with('exito', 'Requisito quitado del brief.');
    }

    /**
     * Añade un mercado a la campaña.
     *
     * A diferencia del brief, **sí se puede con la campaña confirmada**
     * (decisión de negocio, 2026-08-25): ampliar a un país nuevo es comercial y
     * no rompe nada de lo prometido. Lo que no se puede es quitar, y eso lo
     * impide `tg_cm_no_quitar_confirmada` en la base.
     */
    public function anadirMercado(GuardarMercadoRequest $peticion, string $uuid): RedirectResponse
    {
        $campana = $this->campana($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        // El mismo pais dos veces lo rechaza `uq_cm_campaign_country`. Se
        // traduce y no se absorbe: repetir un pais no es un valor que el
        // sistema pueda recalcular --es que el operador queria cambiar el cupo
        // del que ya esta--. Es `DEC-087` otra vez.
        try {
            DB::table('campaign_markets')->insert($datos + [
                'campaign_id' => $campana->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            if (!Choque::esDe($e, 'uq_cm_campaign_country')) {
                throw $e;
            }

            return back()->withInput()->with('aviso',
                'Ese pais ya es un mercado de esta campana. Cambie su cupo en la fila que hay en '
                .'vez de anadirlo otra vez.');
        }

        Bitacora::registrar(
            accion: 'campaign.market_added',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['mercado' => ['antes' => null, 'despues' => $datos]],
        );

        return redirect()->route('campanas.show', $uuid)->with('exito', 'Mercado anadido.');
    }

    /**
     * Quita un mercado de la campaña.
     *
     * Los dos motivos por los que puede no poderse —campaña confirmada, o el
     * mercado tiene cosas colgando— se dicen **antes** de intentarlo. El segundo
     * lo rechazaría la foránea con un `1451` que habla de una fila padre y nombra
     * un índice: cierto, y de ninguna ayuda.
     */
    public function quitarMercado(string $uuid, int $mercado): RedirectResponse
    {
        $campana = $this->campana($uuid);

        // El par (campana, mercado) y no solo el id: el mercado puede existir y
        // ser de OTRA campana, y eso es lo que hay que impedir.
        $fila = DB::table('campaign_markets')
            ->where('id', $mercado)->where('campaign_id', $campana->id)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('Ese mercado no es de esta campana.');
        }

        if (($aviso = Mercados::vetoParaQuitar($campana, $mercado)) !== null) {
            return back()->with('aviso', $aviso);
        }

        DB::table('campaign_markets')->where('id', $mercado)->delete();

        Bitacora::registrar(
            accion: 'campaign.market_removed',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['mercado' => ['antes' => (array) $fila, 'despues' => null]],
        );

        return redirect()->route('campanas.show', $uuid)->with('exito', 'Mercado quitado.');
    }

    /**
     * El brief efectivo de un mercado, aplicando `N-03`.
     *
     * Pantalla propia y no una pestaña de la ficha porque es la respuesta a una
     * pregunta distinta: la ficha dice *qué se ha escrito*, y ésta dice **qué le
     * toca a este país** — que con la regla de reemplazo no es lo mismo.
     */
    public function mercado(string $uuid, int $mercado): View
    {
        $campana = $this->campana($uuid);

        $fila = DB::table('campaign_markets as m')
            ->join('countries as p', 'p.id', '=', 'm.country_id')
            ->where('m.id', $mercado)->where('m.campaign_id', $campana->id)
            ->first(['m.id', 'm.target_creators', 'p.name as pais', 'p.iso2']);

        if ($fila === null) {
            throw new NotFoundHttpException('Ese mercado no es de esta campana.');
        }

        return view('campanas.mercado', [
            'campana' => $campana,
            'mercado' => $fila,
            'brief' => Mercados::briefEfectivo((int) $campana->id, $mercado),
            'propio' => in_array($mercado, Mercados::conBriefPropio((int) $campana->id), true),
        ]);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Por qué esta campaña ya no se edita, o `null` si sí.
     *
     * Se separa del `abort(409)` de `edit()` porque el brief se toca desde otra
     * pantalla y con otro verbo: un 409 en un `POST` de formulario deja al
     * operador en una página de error en vez de devolverlo a la ficha con el
     * motivo.
     */
    private static function vetoPorNoEditable(object $campana): ?string
    {
        if (in_array((string) $campana->status, EstadosDeCampana::INICIALES, true)) {
            return null;
        }

        return 'El brief de una campana confirmada no se cambia: lo que hay que entregar es lo '
            .'que se le prometio al cliente. Cambiarlo con creadores dentro exige una enmienda '
            .'aceptada por las dos partes (BR-CAMPAIGN-003), no un formulario.';
    }

    private function campana(string $uuid): object
    {
        $fila = DB::table('campaigns')->where('uuid', $uuid)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return $fila;
    }

    /** @return array<string, string> destino => nombre para la pantalla */
    private function transicionesDisponibles(string $estado): array
    {
        $usuarioId = (int) Auth::id();
        $salida = [];

        foreach (EstadosDeCampana::desde($estado) as $destino => $permiso) {
            if (Permisos::tiene($usuarioId, $permiso)) {
                $salida[$destino] = EstadosDeCampana::NOMBRES[$destino];
            }
        }

        return $salida;
    }

    /** @return array<string, mixed> */
    private function datosDelFormulario(): array
    {
        return [
            'clientes' => DB::table('client_organizations')
                ->whereNotIn('status', ['inactive', 'blacklisted'])
                ->orderBy('commercial_name')
                ->get(['id', 'commercial_name', 'country_id']),
            'marcas' => DB::table('client_brands')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'client_organization_id', 'name']),
            'monedas' => DB::table('currencies')->orderBy('code')->get(['code', 'name']),
            'objetivos' => Campanas::OBJETIVOS,
            'hoy' => now()->toDateString(),
            'campana' => null,
            // El alta no sabe todavia quien facturara: la respuesta depende del
            // pais del cliente Y de la fecha de inicio, y no hay ninguno de los
            // dos hasta que se teclean. Se deja en `null` a proposito en vez de
            // resolver «con la cobertura de hoy» y ensenarlo: eso es exactamente
            // el «deducirlo de la configuracion vigente» que prohibe `BR-LE-001`,
            // y una pantalla que nombra una sociedad que luego no es la que
            // factura es peor que una que dice que todavia no lo sabe. Se
            // contesta al guardar, en el mensaje de la redireccion.
            'sociedad' => null,
        ];
    }
}
