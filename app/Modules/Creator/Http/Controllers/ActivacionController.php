<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\RegistrarAceptacionRequest;
use App\Modules\Creator\Http\Requests\VerificarIdentidadRequest;
use App\Modules\Creator\Services\CompletitudOperativa;
use App\Shared\Audit\Bitacora;
use App\Shared\Files\Almacen;
use App\Shared\Workflow\Transicion;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La puerta entre `pending` y `active` (iteración 3.5).
 *
 * La 3.4 dejaba a todos los creadores en `pending` y no había forma de salir de
 * ahí. Esta pantalla es la salida, y es una puerta y no un botón: enseña las
 * seis condiciones de `BR-CREATOR-006` / `BR-CREATOR-010`, cuáles se cumplen y
 * qué falta para las que no.
 *
 * Tres cosas que se hacen a propósito:
 *
 * 1. **La comprobación se repite en el servidor.** El botón de activar sale
 *    deshabilitado cuando falta algo, pero eso es cortesía con el operador, no
 *    seguridad: un `POST` a mano se salta cualquier atributo del HTML. La
 *    autoridad es `CompletitudOperativa` ejecutándose otra vez dentro de
 *    `activar()`.
 *
 * 2. **La activación es condicional en la propia base.** El `UPDATE` lleva
 *    `WHERE status = 'pending'` y se mira cuántas filas cambió. Si dos
 *    operadores pulsan a la vez, solo uno escribe la transición; el otro recibe
 *    un aviso en lugar de duplicar el histórico.
 *
 * 3. **Se escribe en `status_transitions`, no solo en la bitácora.** Son cosas
 *    distintas: la bitácora prueba quién tocó qué, el histórico de estados es lo
 *    que permite medir cuánto tarda un creador en llegar a activo y cuántos se
 *    quedan por el camino (`docs/02` N-04).
 */
final class ActivacionController
{
    public function show(string $uuid): View
    {
        $creador = $this->porUuid($uuid);
        $requisitos = CompletitudOperativa::revisar((int) $creador->id);

        return view('creadores.activacion', [
            'creador' => $creador,
            'requisitos' => $requisitos,
            'completo' => CompletitudOperativa::completa($requisitos),
            'terminos' => DB::table('terms_versions')
                ->where('audience', 'creator')
                ->where('code', (string) config('latam.terminos.creador', 'creator_terms'))
                ->whereNull('effective_to')
                ->first(['id', 'code', 'version', 'title', 'effective_from']),
            'historico' => DB::table('status_transitions')
                ->where('entity_type', 'creator')
                ->where('entity_id', $creador->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['from_status', 'to_status', 'reason', 'occurred_at']),
        ]);
    }

    // ------------------------------------------------------ evidencia: identidad

    public function verificarIdentidad(VerificarIdentidadRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        // BR-CREATOR-009: sobre un creador anonimizado no se registra nada nuevo.
        // Sus datos personales ya no existen; no hay identidad que cotejar.
        if ($creador->anonymized_at !== null) {
            return back()->with('aviso', 'Este creador está anonimizado: no se pueden registrar datos personales.');
        }

        $archivo = $request->file('documento');

        if (!$archivo instanceof UploadedFile) {
            return back()->with('aviso', 'No llegó el archivo. Vuelve a intentarlo.');
        }

        $fileId = Almacen::guardar($archivo, 'identity_document');

        // Las tres columnas van juntas o no van: `ck_creators_identity_evidence`
        // lo impone en la base, y esta es la única escritura que las toca.
        DB::table('creators')->where('id', $creador->id)->update([
            'identity_verified_at' => now(),
            'identity_verified_by_user_id' => $request->user()?->getAuthIdentifier(),
            'identity_document_file_id' => $fileId,
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator.identity_verified',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'identity_verified_at' => [
                    'antes' => $creador->identity_verified_at,
                    'despues' => (string) now(),
                ],
                'identity_document_file_id' => ['antes' => $creador->identity_document_file_id, 'despues' => $fileId],
                'nota_del_revisor' => ['antes' => null, 'despues' => $request->input('nota')],
            ],
        );

        return redirect()
            ->route('creadores.activacion', $uuid)
            ->with('exito', 'Identidad verificada y documento archivado.');
    }

    // ------------------------------------------------------ evidencia: términos

    public function registrarTerminos(RegistrarAceptacionRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        $version = DB::table('terms_versions')
            ->where('audience', 'creator')
            ->where('code', (string) config('latam.terminos.creador', 'creator_terms'))
            ->whereNull('effective_to')
            ->first(['id', 'version', 'effective_from']);

        if ($version === null) {
            return back()->with('aviso',
                'No hay una versión vigente de los términos del creador. Publícala antes de registrar aceptaciones.');
        }

        // El campo `datetime-local` del navegador manda «2026-08-23T10:00». MySQL
        // lo acepta, pero no todos los formatos que un navegador puede mandar, y
        // una fecha mal interpretada aquí queda escrita para siempre en una tabla
        // de solo inserción. Se normaliza una vez, y desde ahí es un objeto.
        $aceptadoEn = CarbonImmutable::parse((string) $request->input('accepted_at'));

        // No se puede aceptar algo que todavía no estaba publicado. Es la
        // comprobación que convierte la fecha en un dato y no en un adorno.
        if ($aceptadoEn->toDateString() < (string) $version->effective_from) {
            return back()->withInput()->with('aviso',
                "La versión vigente entró en vigor el {$version->effective_from}: nadie pudo aceptarla antes.");
        }

        if (DB::table('terms_acceptances')
            ->where('subject_type', 'creator')
            ->where('subject_id', $creador->id)
            ->where('terms_version_id', $version->id)
            ->exists()
        ) {
            return back()->with('aviso', 'Ya consta la aceptación de la versión vigente.');
        }

        $archivo = $request->file('evidencia');

        if (!$archivo instanceof UploadedFile) {
            return back()->with('aviso', 'No llegó el archivo. Vuelve a intentarlo.');
        }

        $fileId = Almacen::guardar($archivo, 'terms_evidence');

        $aceptacionId = DB::table('terms_acceptances')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'terms_version_id' => $version->id,
            'subject_type' => 'creator',
            'subject_id' => $creador->id,
            'channel' => (string) $request->input('channel'),
            'recorded_by_user_id' => $request->user()?->getAuthIdentifier(),
            'evidence_file_id' => $fileId,
            'evidence_note' => $request->input('evidence_note'),
            // La IP y el navegador son los del REVISOR, no los del creador: la
            // aceptación llegó por correo o por WhatsApp. Se guardan igual,
            // porque documentan quién tecleó esto y desde dónde.
            'ip_address' => self::ipEmpaquetada($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'accepted_at' => $aceptadoEn->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator.terms_accepted',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'terms_version' => ['antes' => null, 'despues' => $version->version],
                'canal' => ['antes' => null, 'despues' => $request->input('channel')],
                'aceptado_el' => ['antes' => null, 'despues' => $aceptadoEn->toDateTimeString()],
                'aceptacion_id' => ['antes' => null, 'despues' => $aceptacionId],
            ],
        );

        return redirect()
            ->route('creadores.activacion', $uuid)
            ->with('exito', 'Aceptación registrada con su evidencia.');
    }

    // ----------------------------------------------------------- la activación

    public function activar(Request $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        if ($creador->status !== 'pending') {
            return back()->with('aviso', "Solo se activa a un creador en «pendiente»; este está en «{$creador->status}».");
        }

        // La autoridad. El botón deshabilitado de la vista es cortesía; esto es
        // la puerta.
        $requisitos = CompletitudOperativa::revisar((int) $creador->id);

        if (!CompletitudOperativa::completa($requisitos)) {
            return back()->with('aviso', 'Todavía falta: '
                .implode('; ', CompletitudOperativa::pendientes($requisitos)).'.');
        }

        $motivo = trim((string) $request->input('motivo', ''));

        $activado = DB::transaction(function () use ($creador, $motivo, $requisitos): bool {
            // Condicional: si otro operador se adelantó, esto cambia 0 filas y
            // no se escribe ni la transición ni la bitácora.
            $filas = DB::table('creators')
                ->where('id', $creador->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'active',
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($filas === 0) {
                return false;
            }

            Transicion::registrar(
                tipoEntidad: 'creator',
                idEntidad: (int) $creador->id,
                desde: 'pending',
                hacia: 'active',
                motivo: $motivo === '' ? 'Completitud operativa verificada (BR-CREATOR-006).' : $motivo,
            );

            // Se congela POR QUÉ se pudo activar. Dentro de un año, cuando
            // alguien pregunte con qué se dio por buena esta activación, la
            // respuesta está aquí y no en la memoria de nadie.
            $evidencia = [];
            foreach ($requisitos as $r) {
                $evidencia[$r->codigo] = $r->detalle;
            }

            Bitacora::registrar(
                accion: 'creator.activated',
                tipoEntidad: 'creator',
                idEntidad: (int) $creador->id,
                cambios: [
                    'status' => ['antes' => 'pending', 'despues' => 'active'],
                    'completitud' => ['antes' => null, 'despues' => $evidencia],
                ],
            );

            return true;
        });

        if (!$activado) {
            return back()->with('aviso', 'Otro usuario acaba de activar a este creador.');
        }

        return redirect()
            ->route('creadores.show', $uuid)
            ->with('exito', 'Creador activado. Ya puede recibir invitaciones a campañas.');
    }

    // ------------------------------------------------------------------ apoyo

    private static function ipEmpaquetada(Request $request): ?string
    {
        $ip = $request->ip();
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;

        return $empaquetada === false ? null : $empaquetada;
    }

    private function porUuid(string $uuid): object
    {
        $creador = DB::table('creators')->where('uuid', $uuid)->first();

        if ($creador === null) {
            throw new NotFoundHttpException('Creador no encontrado.');
        }

        return $creador;
    }
}
