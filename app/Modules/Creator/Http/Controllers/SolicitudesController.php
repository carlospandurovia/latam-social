<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\AprobarSolicitudRequest;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La bandeja de solicitudes: por dónde entra un creador al sistema.
 *
 * **Aprobar no es activar.** `BR-CREATOR-006` exige completitud operativa mínima
 * —identidad verificada, una red social validada, datos fiscales y un medio de
 * pago verificado— antes de que un creador pueda trabajar. Aprobar la solicitud
 * lo crea en `pending`; llegar a `active` es otra puerta, y tiene su iteración.
 *
 * Confundir las dos cosas sería el error caro: un creador «aprobado» al que se
 * le puede invitar a una campaña y al que luego no se le puede pagar porque no
 * tiene cuenta bancaria verificada.
 */
final class SolicitudesController
{
    public function index(Request $request): View
    {
        $estado = trim((string) $request->query('estado', 'submitted'));

        $consulta = DB::table('creator_applications as s')
            ->join('countries as p', 'p.id', '=', 's.country_id')
            ->leftJoin('users as u', 'u.id', '=', 's.reviewed_by_user_id')
            ->select([
                's.uuid', 's.full_name', 's.email', 's.phone', 's.status',
                's.source', 's.submitted_at', 's.reviewed_at', 's.rejection_note',
                'p.name as pais', 'u.name as revisor',
            ])
            ->orderByDesc('s.id');

        if ($estado !== '' && $estado !== 'todas') {
            $consulta->where('s.status', $estado);
        }

        return view('solicitudes.index', [
            'solicitudes' => $consulta->paginate(25)->withQueryString(),
            'estado' => $estado,
            'conteos' => DB::table('creator_applications')
                ->selectRaw('status, COUNT(*) as n')
                ->groupBy('status')
                ->pluck('n', 'status'),
        ]);
    }

    public function show(string $uuid): View
    {
        $solicitud = $this->porUuid($uuid);

        return view('solicitudes.show', [
            'solicitud' => $solicitud,
            'pais' => DB::table('countries')->where('id', $solicitud->country_id)->first(),
            'posiblesDuplicados' => $this->posiblesDuplicados($solicitud),
            'paises' => DB::table('countries')->where('is_active', 1)->orderBy('name')->get(['iso2', 'name']),
            'monedas' => DB::table('currencies')->where('is_active', 1)->orderBy('code')->get(['code', 'name']),
        ]);
    }

    public function aprobar(AprobarSolicitudRequest $request, string $uuid): RedirectResponse
    {
        $solicitud = $this->porUuid($uuid);

        if ($solicitud->status !== 'submitted' && $solicitud->status !== 'in_review') {
            return back()->with('aviso', 'Esta solicitud ya fue resuelta.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        unset($datos['confirma_revision']);

        // BR-CREATOR-003: el servidor vuelve a comprobar los duplicados. La
        // casilla del formulario dice que el revisor MIRÓ; no le da permiso para
        // crear una colisión. Se comprueba con los datos que acaba de teclear,
        // que son los que van a entrar.
        // SIN filtrar por estado.
        //
        // Antes miraba solo `pending|active|suspended`, pero las unicas que de
        // verdad protegen —`uq_creators_email` y `uq_creators_identity`— se
        // apoyan en `identity_gate`, que vale 1 mientras `anonymized_at IS NULL`
        // y **no mira el estado**. O sea que un creador `blacklisted`,
        // `rejected` o `inactive` seguia ocupando su correo y su documento, y
        // esta guarda no lo veia: el `INSERT` reventaba con `1062` dentro de la
        // transaccion y el revisor veia un 500 en vez de «esta persona esta en
        // lista negra», que es justo lo que necesita saber.
        $choque = DB::table('creators')
            ->whereNull('anonymized_at')
            ->where(function ($w) use ($datos, $solicitud): void {
                $w->where('email', $solicitud->email)
                    ->orWhere(function ($d) use ($datos): void {
                        $d->where('document_country_code', $datos['document_country_code'])
                            ->where('document_type', $datos['document_type'])
                            ->where('document_number', $datos['document_number']);
                    });
            })
            ->first(['uuid', 'display_name', 'email', 'status']);

        if ($choque !== null) {
            return back()
                ->withInput()
                ->with('choque', sprintf(
                    'Ya existe «%s» (%s, estado: %s) con ese correo o documento. %s',
                    $choque->display_name,
                    $choque->email,
                    $choque->status,
                    // El estado cambia lo que hay que hacer, asi que se dice. Un
                    // creador en lista negra no es un duplicado administrativo.
                    in_array($choque->status, ['blacklisted', 'rejected'], true)
                        ? 'No es un duplicado que se resuelva corrigiendo datos: esa persona ya fue apartada. '
                          .'Si hay que readmitirla, es una decision aparte.'
                        : 'Resuelvelo antes de aprobar: marca esta solicitud como duplicada, o corrige el documento.',
                ));
        }

        $creadorId = DB::transaction(function () use ($solicitud, $datos): int {
            $id = (int) DB::table('creators')->insertGetId($datos + [
                'uuid' => (string) Str::uuid(),
                'email' => $solicitud->email,
                'phone' => $solicitud->phone,
                'country_id' => $solicitud->country_id,
                'application_id' => $solicitud->id,
                // NO es `active`: BR-CREATOR-006 exige completitud operativa.
                'status' => 'pending',
                'locale' => 'es',
                'timezone' => 'America/Lima',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('creator_applications')->where('id', $solicitud->id)->update([
                'status' => 'approved',
                'creator_id' => $id,
                'reviewed_by_user_id' => request()->user()?->getAuthIdentifier(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });

        Bitacora::registrar(
            accion: 'creator_application.approved',
            tipoEntidad: 'creator_application',
            idEntidad: (int) $solicitud->id,
            cambios: [
                'status' => ['antes' => $solicitud->status, 'despues' => 'approved'],
                'creator_id' => ['antes' => null, 'despues' => $creadorId],
            ],
        );
        Bitacora::registrar(
            accion: 'creator.created',
            tipoEntidad: 'creator',
            idEntidad: $creadorId,
            cambios: ['origen' => ['antes' => null, 'despues' => 'solicitud '.$solicitud->uuid]],
        );

        $creador = DB::table('creators')->where('id', $creadorId)->first(['uuid']);

        return redirect()
            ->route('creadores.show', $creador->uuid)
            ->with('exito', 'Creador dado de alta en estado «pendiente». Para activarlo faltan '
                .'identidad verificada, red social, datos fiscales y medio de pago (BR-CREATOR-006).');
    }

    public function rechazar(Request $request, string $uuid): RedirectResponse
    {
        $solicitud = $this->porUuid($uuid);

        if ($solicitud->status !== 'submitted' && $solicitud->status !== 'in_review') {
            return back()->with('aviso', 'Esta solicitud ya fue resuelta.');
        }

        $datos = $request->validate([
            // Un rechazo sin motivo no se puede explicar al creador ni auditar.
            'rejection_note' => ['required', 'string', 'min:10', 'max:255'],
            'motivo' => ['required', 'in:rejected,duplicate'],
        ]);

        DB::table('creator_applications')->where('id', $solicitud->id)->update([
            'status' => $datos['motivo'],
            'rejection_note' => $datos['rejection_note'],
            'reviewed_by_user_id' => $request->user()?->getAuthIdentifier(),
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_application.'.$datos['motivo'],
            tipoEntidad: 'creator_application',
            idEntidad: (int) $solicitud->id,
            cambios: [
                'status' => ['antes' => $solicitud->status, 'despues' => $datos['motivo']],
                'rejection_note' => ['antes' => null, 'despues' => $datos['rejection_note']],
            ],
        );

        return redirect()->route('solicitudes.index')->with('exito', 'Solicitud resuelta y registrada.');
    }

    /**
     * BR-CREATOR-003: se avisa ANTES de crear. Solo por correo, que es lo único
     * que trae la solicitud; el documento se comprueba al aprobar, cuando ya se
     * tecleó.
     */
    /** @return Collection<int, \stdClass> */
    private function posiblesDuplicados(object $solicitud): Collection
    {
        return DB::table('creators')
            ->where('email', $solicitud->email)
            ->orderByDesc('id')
            ->get(['uuid', 'display_name', 'email', 'status', 'document_type', 'document_number']);
    }

    private function porUuid(string $uuid): object
    {
        $solicitud = DB::table('creator_applications')->where('uuid', $uuid)->first();

        if ($solicitud === null) {
            throw new NotFoundHttpException('Solicitud no encontrada.');
        }

        return $solicitud;
    }
}
