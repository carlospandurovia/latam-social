<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Campaign\Services\BuscadorDeCreadores;
use App\Modules\Campaign\Services\Campanas;
use App\Modules\Campaign\Services\ListaCorta;
use App\Shared\Audit\Bitacora;
use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'lista' => ListaCorta::de((int) $campana->id),
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
        $fila = DB::table('campaign_creators')
            ->where('id', $participacion)->where('campaign_id', $campana->id)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('Esa participacion no es de esta campana.');
        }

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

    private function campana(string $uuid): object
    {
        $fila = DB::table('campaigns')->where('uuid', $uuid)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return $fila;
    }
}
