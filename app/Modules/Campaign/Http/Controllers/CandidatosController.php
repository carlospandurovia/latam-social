<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Campaign\Services\BuscadorDeCreadores;
use App\Modules\Campaign\Services\Campanas;
use App\Modules\Campaign\Services\Compromiso;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Core\Services\Politica;
use App\Shared\Audit\Bitacora;
use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Buscar creadores para una campaña y armar la lista corta (7.4).
 *
 * ### Una pantalla por campaña, no un buscador global
 *
 * Podría haber sido «buscar creadores» a secas, con la campaña como un filtro
 * más. Se eligió al revés —la campaña es el contexto, no el filtro— porque es
 * lo que hace que las reglas se apliquen solas: los mercados, la edad mínima,
 * los formatos del brief y las categorías de la marca ya están puestos cuando
 * la pantalla abre.
 *
 * Un buscador global obligaría a que alguien se acordara de las cuatro cosas
 * cada vez. Y el día que se olvide, el síntoma no es un error: es una
 * invitación a la persona equivocada.
 */
final class CandidatosController
{
    public function index(Request $peticion, string $uuid): View
    {
        $campana = $this->campana($uuid);

        $filtros = [
            'texto' => (string) $peticion->query('texto', ''),
            'categoria' => (int) $peticion->query('categoria', 0),
            'formato' => (int) $peticion->query('formato', 0),
            'plataforma' => (int) $peticion->query('plataforma', 0),
        ];

        // El interruptor de auditoria. Sin el, «¿por que no me sale Fulano?» se
        // contesta abriendo la base de datos.
        $verDescartados = $peticion->boolean('descartados');

        $candidatos = $verDescartados
            ? BuscadorDeCreadores::conDescartados($campana, $filtros)
            : BuscadorDeCreadores::buscar($campana, $filtros);

        $lista = ListaCorta::de((int) $campana->id);

        return view('campanas.candidatos', [
            'campana' => $campana,
            'candidatos' => $candidatos,
            'costes' => BuscadorDeCreadores::costeEstimado(
                $campana, $candidatos->pluck('id')->map(fn ($x): int => (int) $x)->all(),
            ),
            'filtros' => $filtros,
            'verDescartados' => $verDescartados,
            'motivos' => BuscadorDeCreadores::MOTIVOS,
            'edadMinima' => BuscadorDeCreadores::edadMinima($campana),
            'lista' => $lista,
            // 7.6: en que anda la invitacion de cada uno. Se calcula aqui y no
            // en la plantilla: una consulta dentro de un `foreach` de Blade es
            // como se llega a cuarenta consultas sin que se note.
            'invitaciones' => $lista->mapWithKeys(fn (object $f): array => [
                (int) $f->id => Invitaciones::viva((int) $f->id),
            ]),
            // `T-38`: las preguntas del creador. Una pregunta que nadie lee es
            // peor que no poder preguntar --el creador se queda esperando y
            // ademas cree que nos importa--, asi que salen en la misma pantalla
            // donde se decide sobre el.
            'preguntas' => $lista->mapWithKeys(fn (object $f): array => [
                (int) $f->id => Invitaciones::preguntas((int) $f->id),
            ]),
            'compromiso' => [
                'comprometido' => Compromiso::comprometido((int) $campana->id),
                'presupuesto' => (float) $campana->creator_budget_amount,
                'autorizado' => $campana->budget_override_at !== null,
                'motivo' => $campana->budget_override_reason,
            ],
            'requisitos' => Campanas::requisitos((int) $campana->id),
            'mercados' => DB::table('campaign_markets as m')
                ->join('countries as p', 'p.id', '=', 'm.country_id')
                ->where('m.campaign_id', $campana->id)
                ->orderBy('p.name')->get(['p.name', 'p.iso2']),
            'categorias' => DB::table('categories')->where('is_active', 1)
                ->orderBy('code')->get(['id', 'code']),
            'formatos' => DB::table('content_formats as f')
                ->leftJoin('platforms as p', 'p.id', '=', 'f.platform_id')
                ->where('f.is_active', 1)->orderBy('p.name')->orderBy('f.code')
                ->get(['f.id', 'f.code', 'p.name as red']),
            'plataformas' => DB::table('platforms')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function anadir(Request $peticion, string $uuid): RedirectResponse
    {
        $campana = $this->campana($uuid);
        $creadorId = (int) $peticion->input('creator_id');

        if (($motivos = ListaCorta::vetoParaAnadir($campana, $creadorId)) !== []) {
            return back()->with('aviso', sprintf(
                'Ese creador todavia no puede entrar en la lista corta. Falta: %s.',
                implode('; ', $motivos),
            ));
        }

        // Dos operadores mirando la misma pantalla pulsan a la vez y los dos
        // pasan el veto: el segundo `INSERT` choca con `uq_ccr_campaign_creator`.
        // Se TRADUCE, no se absorbe --el creador lo eligio una persona-- pero el
        // resultado que queria ya esta, asi que el mensaje lo dice asi y no como
        // un error. Es `DEC-087` aplicado al caso amable.
        try {
            ListaCorta::anadir($campana, $creadorId);
        } catch (Throwable $e) {
            if (!Choque::esDe($e, 'uq_ccr_campaign_creator')) {
                throw $e;
            }

            return back()->with('aviso', 'Ese creador ya estaba en la lista de esta campana.');
        }

        Bitacora::registrar(
            accion: 'campaign.creator_shortlisted',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['creador' => ['antes' => null, 'despues' => $creadorId]],
        );

        return back()->with('exito', 'Creador anadido a la lista corta.');
    }

    public function quitar(string $uuid, int $participacion): RedirectResponse
    {
        $campana = $this->campana($uuid);

        // El par (campana, participacion), no solo el id: la participacion puede
        // existir y ser de OTRA campana. Misma leccion que en marcas, requisitos
        // y mercados.
        $fila = $this->participacion($campana, $participacion);

        if (($aviso = ListaCorta::vetoParaQuitar($fila)) !== null) {
            return back()->with('aviso', $aviso);
        }

        DB::table('campaign_creators')->where('id', $participacion)->delete();

        Bitacora::registrar(
            accion: 'campaign.creator_unshortlisted',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['creador' => ['antes' => (int) $fila->creator_id, 'despues' => null]],
        );

        return back()->with('exito', 'Creador quitado de la lista corta.');
    }

    /**
     * Fija el monto acordado de una participación (7.5).
     *
     * Tres vetos, en este orden, y el orden importa:
     *
     * 1. **¿Ya aceptó?** Entonces no hay nada que discutir: el número está
     *    congelado (`BR-CREATOR-008`).
     * 2. **¿Cabe en el presupuesto?** (`BR-CAMPAIGN-005`.)
     * 3. Y sólo entonces se escribe.
     *
     * Preguntar por el presupuesto antes que por el congelado diría «no cabe»
     * sobre un importe que de todas formas no se podía cambiar.
     */
    public function comprometer(Request $peticion, string $uuid, int $participacion): RedirectResponse
    {
        $campana = $this->campana($uuid);
        $fila = $this->participacion($campana, $participacion);

        if (($aviso = Compromiso::vetoPorCongelado($fila)) !== null) {
            return back()->with('aviso', $aviso);
        }

        // 7.6: y si hay una invitacion VIVA, el creador esta mirando la cifra
        // anterior. `tg_ccr_monto_con_invitacion` lo impide en la base; aqui se
        // dice con palabras y se explica la salida --anular y volver a invitar--
        // en vez de dejar que reviente el UPDATE con un SIGNAL.
        if (($aviso = Invitaciones::vetoPorInvitacionViva($participacion)) !== null) {
            return back()->with('aviso', $aviso);
        }

        // 9.18: se puede pactar el COSTO --lo de siempre-- o **el neto que el
        // creador recibe**, que es lo que pidio el negocio (`Q-40`): «te pagare
        // 100 soles pero lo que estaria provisionando serian 141,84».
        $base = $peticion->input('agreed_basis') === 'net' ? 'net' : 'gross';
        $tecleado = round((float) $peticion->input('agreed_amount', 0), 4);

        if ($tecleado < 0) {
            return back()->with('aviso', 'El monto acordado no puede ser negativo.');
        }

        $politica = Politica::datos();
        $neto = null;
        $tasa = null;

        if ($base === 'net') {
            if ($politica['tasa'] <= 0) {
                // No se bloquea el pactar: se bloquea el ENGANO de decir que se
                // pacto un neto cuando no hay retencion con la que convertirlo.
                // Con tasa 0 el neto y el costo son el mismo numero, y guardarlo
                // como «neto» haria creer que se retuvo algo.
                return back()->with('aviso',
                    'No hay retención configurada, así que pactar «lo que recibe el creador» daría '
                    .'exactamente el mismo número que pactar el costo. Póngala en Política de '
                    .'precios, o pacte el costo directamente.');
            }

            $neto = $tecleado;
            $tasa = $politica['tasa'];
            $importe = Politica::brutoDesdeNeto($neto, $tasa);
        } else {
            $importe = $tecleado;
        }

        if (($aviso = Compromiso::vetoPorPresupuesto($campana, $importe, $participacion)) !== null) {
            return back()->with('aviso', $aviso);
        }

        DB::table('campaign_creators')->where('id', $participacion)->update([
            'agreed_amount' => $importe,
            'agreed_basis' => $base,
            'agreed_net_amount' => $neto,
            'withholding_rate_snapshot' => $tasa,
            // El umbral se congela SIEMPRE, tambien cuando se pacta el costo:
            // es con lo que se juzgo esta participacion, y subirlo manana no
            // puede reescribir ese juicio (BR-FIN-012 aplicado al margen).
            'min_margin_pct_snapshot' => $politica['configurada'] ? $politica['umbral'] : null,
            'margin_basis_snapshot' => $politica['configurada'] ? $politica['base'] : null,
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'campaign.creator_amount_set',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['monto' => [
                'antes' => (float) $fila->agreed_amount,
                'despues' => $importe,
            ]],
        );

        // Lo que el negocio pidio ver: «el ingreso aceptable mas bajo por este
        // creador seria de 170.21 soles». Es informacion, no una puerta: quien
        // lleva la campana decide si acepta menos margen, y para eso tiene que
        // ver el numero ANTES de invitar (DEC-214).
        $mensaje = $base === 'net'
            ? sprintf(
                'Pactado: el creador recibe %s y la campaña provisiona %s (retención %s %%).',
                number_format((float) $neto, 2), number_format($importe, 2),
                rtrim(rtrim(number_format((float) $tasa, 4, '.', ''), '0'), '.'))
            : 'Monto acordado actualizado.';

        if ($politica['configurada'] && $politica['umbral'] > 0 && $importe > 0) {
            $mensaje .= sprintf(
                ' Para dejar el %s %% sobre el %s, el ingreso atribuible a esta participación '
                .'tendría que llegar a %s.',
                rtrim(rtrim(number_format($politica['umbral'], 4, '.', ''), '0'), '.'),
                $politica['base'] === Politica::INGRESO ? 'ingreso' : 'costo',
                number_format(Politica::ingresoMinimo($importe), 2),
            );
        }

        return back()->with('exito', $mensaje);
    }

    /**
     * Manda la invitación (7.6).
     *
     * El correo sale por evento —`CorreoPedido`, que vive en `Shared`— porque
     * `deptrac.yaml` no deja que Campaign conozca Communication. No es
     * burocracia: un SMTP caído no puede tumbar una invitación que ya está
     * escrita y cuyo plazo ya corre.
     */
    public function invitar(string $uuid, int $participacion): RedirectResponse
    {
        $campana = $this->campana($uuid);
        $fila = $this->participacion($campana, $participacion);

        $motivos = Invitaciones::vetoParaInvitar($campana, $fila);

        if ($motivos !== []) {
            return back()->with('aviso', 'No se puede invitar a ese creador: '
                .implode('; ', $motivos).'.');
        }

        Invitaciones::invitar($campana, $fila, Auth::id());

        return back()->with('exito', sprintf(
            'Invitacion enviada. Tiene %d horas para contestar; si no, la participacion '
            .'queda como caducada y su importe deja de contar contra el presupuesto.',
            (int) ($campana->invitation_hours ?: 72),
        ));
    }

    /**
     * Anula la invitación viva.
     *
     * Es la salida cuando hay que renegociar: sin esto, el importe no se puede
     * tocar mientras el creador tenga una oferta delante — que es exactamente lo
     * que `tg_ccr_monto_con_invitacion` protege.
     *
     * La participación vuelve a `shortlisted`. **No a `cancelled`**: anular una
     * oferta que nadie contestó no es sacar al creador de la campaña.
     */
    public function anularInvitacion(Request $peticion, string $uuid, int $participacion): RedirectResponse
    {
        $campana = $this->campana($uuid);
        $fila = $this->participacion($campana, $participacion);

        if (Invitaciones::viva($participacion) === null) {
            return back()->with('aviso', 'Ese creador no tiene ninguna invitacion viva que anular.');
        }

        $motivo = trim((string) $peticion->input('motivo', ''));

        DB::transaction(function () use ($participacion, $motivo): void {
            // `ck_inv_revoked` exige motivo. El del formulario puede venir
            // vacio; entonces se anota el hecho --que la anulo una persona-- y
            // no una cadena vacia que no explica nada.
            Invitaciones::anular($participacion, $motivo !== '' ? mb_substr($motivo, 0, 40) : 'anulada_a_mano');

            DB::table('campaign_creators')->where('id', $participacion)->update([
                'status' => ListaCorta::SHORTLISTED,
                'invited_at' => null,
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'campaign.invitation_revoked',
            tipoEntidad: 'campaign_creator',
            idEntidad: $participacion,
            cambios: [
                'status' => ['antes' => $fila->status, 'despues' => ListaCorta::SHORTLISTED],
                'motivo' => ['antes' => null, 'despues' => $motivo],
            ],
        );

        return back()->with('exito', 'Invitacion anulada. El creador vuelve a la lista corta '
            .'y ya se le puede cambiar el importe.');
    }

    /**
     * Alguien del equipo se hace cargo de una pregunta (`T-38`).
     *
     * **No es una respuesta.** La respuesta va por correo, que es donde el
     * creador está. Esto marca un dueño, que es lo que hace que una pregunta no
     * se quede huérfana en una lista que todos miran y nadie atiende.
     */
    public function marcarPreguntaVista(string $uuid, int $participacion, int $pregunta): RedirectResponse
    {
        $campana = $this->campana($uuid);

        // El par, no solo el id: la pregunta puede existir y ser de otra
        // campana. Misma leccion que en marcas, requisitos y mercados.
        $this->participacion($campana, $participacion);

        $suya = DB::table('invitation_questions as q')
            ->join('invitations as i', 'i.id', '=', 'q.invitation_id')
            ->where('q.id', $pregunta)
            ->where('i.campaign_creator_id', $participacion)
            ->exists();

        if (!$suya) {
            throw new NotFoundHttpException('Esa pregunta no es de esa participacion.');
        }

        Invitaciones::marcarVista($pregunta, (int) Auth::id());

        return back()->with('exito', 'Pregunta marcada como atendida. Contestale por correo: '
            .'el creador espera ahi, y su invitacion sigue corriendo.');
    }

    /**
     * Finanzas autoriza pasarse del presupuesto de creadores.
     *
     * Lo firma finanzas y no quien montó la campaña — la misma separación que
     * `DEC-091` para aprobar. El **motivo es obligatorio** y lo exige la base
     * (`ck_camp_budget_override`): dentro de un año, *«¿por qué esta campaña se
     * pasó 3.000 soles?»* tiene que responderlo la fila, no la memoria de nadie.
     *
     * Levanta el techo para toda la campaña, no para una participación: se firma
     * una vez, con su motivo.
     */
    public function autorizarSobrecosto(Request $peticion, string $uuid): RedirectResponse
    {
        $campana = $this->campana($uuid);
        $motivo = trim((string) $peticion->input('budget_override_reason', ''));

        if (mb_strlen($motivo) < 10) {
            return back()->with('aviso',
                'Autorizar un sobrecosto exige un motivo de verdad, no una palabra: dentro de un ano '
                .'esta fila es lo unico que va a explicar por que esta campana se paso del presupuesto.');
        }

        DB::table('campaigns')->where('id', $campana->id)->update([
            'budget_override_by_user_id' => Auth::id(),
            'budget_override_at' => now(),
            'budget_override_reason' => mb_substr($motivo, 0, 255),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'campaign.budget_override',
            tipoEntidad: 'campaign',
            idEntidad: (int) $campana->id,
            cambios: ['sobrecosto' => [
                'antes' => (float) $campana->creator_budget_amount,
                'despues' => $motivo,
            ]],
        );

        return back()->with('exito', 'Sobrecosto autorizado y anotado en la bitacora.');
    }

    /** La participación, comprobando que es de ESTA campaña. */
    private function participacion(object $campana, int $id): object
    {
        $fila = DB::table('campaign_creators')
            ->where('id', $id)->where('campaign_id', $campana->id)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('Esa participacion no es de esta campana.');
        }

        return $fila;
    }

    private function campana(string $uuid): object
    {
        $fila = DB::table('campaigns')->where('uuid', $uuid)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return $fila;
    }
}
