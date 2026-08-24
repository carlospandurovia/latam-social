<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\AprobarPerfilFiscalRequest;
use App\Modules\Creator\Http\Requests\GuardarPerfilFiscalRequest;
use App\Shared\Audit\Bitacora;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El perfil tributario del creador (iteración 3.6).
 *
 * `BR-CREATOR-013` es de las reglas más duras del proyecto: **no existe el pago
 * informal**. Sin un perfil aprobado y vigente el creador no se activa, no
 * recibe invitaciones y no se le liquida. Esta es la pantalla donde ese perfil
 * entra al sistema.
 *
 * Cuatro cosas que no son evidentes:
 *
 * 1. **Capturar y aprobar son dos actos, y dos personas.** El permiso está
 *    partido en `creator.tax.manage` y `creator.tax.approve`, y además
 *    `ck_ctp_segregation` exige en la base que el aprobador no sea el capturador.
 *    Es la misma separación que en los lotes de pago (`DEC-044`): aquí se decide
 *    con qué tasa se retiene, y eso toca dinero.
 *
 * 2. **La retención se decide al aprobar, no al capturar** (`DEC-048`). Quien
 *    teclea el RUC no es quien conoce la norma. Por eso el formulario de captura
 *    ni siquiera ofrece el campo.
 *
 * 3. **Aprobar uno nuevo cierra el anterior**, en la misma transacción. Es lo
 *    que exige `BR-CREATOR-007`: un cambio de datos fiscales no surte efecto
 *    hasta que se aprueba. El índice `uq_ctp_current` solo admite un perfil
 *    vigente por creador y país, así que el orden —cerrar y luego abrir— no es
 *    una preferencia: al revés la base lo rechaza.
 *
 * 4. **Rechazar no escribe «aprobado por».** Ver el comentario en `rechazar()`:
 *    reutilizar esa columna para registrar un rechazo choca con
 *    `ck_ctp_segregation` y le impedía al capturador retirar su propio error.
 *
 * 5. **La notificación al canal anterior todavía es manual.** `BR-CREATOR-007`
 *    la exige y el módulo Communication no existe. Se dice en pantalla en vez
 *    de callarlo: un requisito que nadie ve es un requisito que no se cumple.
 *    Ver `T-10`.
 */
final class PerfilFiscalController
{
    public function index(string $uuid): View
    {
        $creador = $this->porUuid($uuid);

        return view('creadores.fiscal', [
            'creador' => $creador,
            'perfiles' => DB::table('creator_tax_profiles as p')
                ->join('countries as c', 'c.id', '=', 'p.country_id')
                ->leftJoin('users as cap', 'cap.id', '=', 'p.created_by_user_id')
                ->leftJoin('users as apr', 'apr.id', '=', 'p.approved_by_user_id')
                ->leftJoin('creator_guardians as g', 'g.id', '=', 'p.holder_guardian_id')
                ->where('p.creator_id', $creador->id)
                ->orderByDesc('p.id')
                ->get([
                    'p.id', 'p.tax_regime_code', 'p.tax_id_type', 'p.tax_id_number',
                    'p.issued_document_type', 'p.status', 'p.valid_from', 'p.valid_to',
                    'p.withholding_status', 'p.withholding_rate', 'p.withholding_basis',
                    'p.holder_type', 'p.rejection_note', 'p.approved_at',
                    'c.name as pais', 'cap.name as capturado_por', 'apr.name as aprobado_por',
                    'g.full_name as tutor',
                ]),
            'paises' => DB::table('countries')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            // Solo tutelas ACTIVAS: poner los datos fiscales a nombre de un
            // tutor cuya tutela se cerró es exactamente el error que la columna
            // `holder_guardian_id` vino a hacer visible.
            'tutores' => DB::table('creator_guardians')
                ->where('creator_id', $creador->id)
                ->where('status', 'active')
                ->get(['id', 'full_name']),
            'esMenor' => CarbonImmutable::parse((string) $creador->birth_date)->age < 18,
        ]);
    }

    public function store(GuardarPerfilFiscalRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        // BR-CREATOR-009: igual que en la verificacion de identidad, sobre un
        // creador anonimizado no se registran datos personales nuevos. Un RUC
        // es un dato personal.
        if ($creador->anonymized_at !== null) {
            return back()->with('aviso', 'Este creador está anonimizado: no se pueden registrar datos fiscales.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        // El id del tutor viene del formulario, así que se comprueba que sea de
        // ESTE creador. Sin esto, un id ajeno colaría los datos fiscales de otra
        // persona: la clave foránea comprueba que el tutor existe, no de quién es.
        if ($datos['holder_type'] === 'guardian') {
            $suyo = DB::table('creator_guardians')
                ->where('id', $datos['holder_guardian_id'])
                ->where('creator_id', $creador->id)
                ->where('status', 'active')
                ->exists();

            if (!$suyo) {
                return back()->withInput()->with('aviso', 'Ese tutor no es de este creador o su tutela no está activa.');
            }
        } else {
            $datos['holder_guardian_id'] = null;
        }

        $id = (int) DB::table('creator_tax_profiles')->insertGetId($datos + [
            'creator_id' => $creador->id,
            // Nace sin decidir la retención. No es un hueco: es DEC-048.
            'withholding_status' => 'pending_review',
            'withholding_rate' => 0,
            'status' => 'pending',
            'created_by_user_id' => $request->user()?->getAuthIdentifier(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_tax_profile.created',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'perfil_fiscal' => ['antes' => null, 'despues' => $id],
                'regimen' => ['antes' => null, 'despues' => $datos['tax_regime_code']],
                'titular' => ['antes' => null, 'despues' => $datos['holder_type']],
            ],
        );

        return redirect()
            ->route('creadores.fiscal', $uuid)
            ->with('exito', 'Perfil capturado. Queda PENDIENTE: tiene que aprobarlo otra persona, '
                .'y al aprobarlo habrá que decidir la retención (DEC-048).');
    }

    public function aprobar(AprobarPerfilFiscalRequest $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $perfil = $this->perfilDe($creador, $id);

        if ($perfil->status !== 'pending') {
            return back()->with('aviso', "Este perfil ya está en «{$perfil->status}».");
        }

        $usuarioId = (int) $request->user()?->getAuthIdentifier();

        // La base lo rechaza igual (`ck_ctp_segregation`), pero un error 45000
        // en pantalla no le dice al operador qué hizo mal.
        if ($usuarioId === (int) $perfil->created_by_user_id) {
            return back()->with('aviso', 'No puedes aprobar un perfil que capturaste tú. '
                .'Tiene que revisarlo otra persona (BR-CREATOR-007).');
        }

        $vigente = DB::table('creator_tax_profiles')
            ->where('creator_id', $creador->id)
            ->where('country_id', $perfil->country_id)
            ->where('status', 'approved')
            ->whereNull('valid_to')
            ->first(['id', 'valid_from', 'tax_regime_code']);

        // El perfil nuevo tiene que empezar DESPUÉS que el vigente (`DEC-071`).
        //
        // Si empezara el mismo día o antes, cerrar el anterior «el día antes»
        // le pondría un `valid_to` anterior a su propio `valid_from`, que es lo
        // que prohíbe `ck_ctp_dates`. Y no se arregla recortando la fecha: lo
        // que ese caso significa de verdad es que el perfil vigente **no
        // estuvo vigente nunca**, y eso no es cerrarlo, es anularlo.
        //
        // Un cambio de régimen ante SUNAT sí puede ser retroactivo, y ahí esta
        // pantalla se queda corta a propósito: reescribir un histórico del que
        // sale la retención practicada necesita rastro de quién y por qué. Está
        // anotado como `Q-48`.
        if ($vigente !== null && (string) $perfil->valid_from <= (string) $vigente->valid_from) {
            return back()->with('aviso', sprintf(
                'Este perfil entra en vigor el %s, y el vigente (%s) empezó el %s. '
                .'El nuevo tiene que empezar después, porque si no habría dos regímenes '
                .'aplicables el mismo día y de ahí sale la retención. Si de verdad hay que '
                .'corregir el histórico hacia atrás, hoy eso se hace en base de datos (DEC-071).',
                $perfil->valid_from,
                $vigente->tax_regime_code,
                $vigente->valid_from,
            ));
        }

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $aplica = $datos['withholding_status'] === 'applies';

        DB::transaction(function () use ($perfil, $datos, $aplica, $usuarioId, $vigente): void {
            if ($vigente !== null) {
                // El anterior se cierra EL DÍA ANTES de que empiece el nuevo.
                //
                // Aquí estaba `T-12`. Antes se cerraba con `valid_to` = el
                // `valid_from` del nuevo, y `valid_to` es INCLUSIVO en todo el
                // esquema: el día del relevo los dos estaban vigentes. La
                // pregunta «¿qué régimen aplicaba el 1 de abril?» tenía dos
                // respuestas, y de esa respuesta sale la retención que se le
                // practicó al creador.
                //
                // Es el mismo defecto que `H-16` cerró en tarifas. Allí se paga
                // explicando una factura; aquí, en una declaración.
                $cierre = CarbonImmutable::parse((string) $perfil->valid_from)
                    ->subDay()
                    ->toDateString();

                DB::table('creator_tax_profiles')->where('id', $vigente->id)->update([
                    'status' => 'superseded',
                    'valid_to' => $cierre,
                    'updated_at' => now(),
                ]);
            }

            DB::table('creator_tax_profiles')->where('id', $perfil->id)->update([
                'withholding_status' => $datos['withholding_status'],
                'withholding_rate' => $aplica ? $datos['withholding_rate'] : 0,
                'withholding_basis' => $aplica ? $datos['withholding_basis'] : null,
                'status' => 'approved',
                'approved_by_user_id' => $usuarioId,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'creator_tax_profile.approved',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'perfil_fiscal' => ['antes' => null, 'despues' => $perfil->id],
                'status' => ['antes' => 'pending', 'despues' => 'approved'],
                'retencion' => ['antes' => 'pending_review', 'despues' => $datos['withholding_status']],
                'tasa' => ['antes' => null, 'despues' => $aplica ? $datos['withholding_rate'] : 0],
                'norma' => ['antes' => null, 'despues' => $aplica ? $datos['withholding_basis'] : null],
            ],
        );

        return redirect()
            ->route('creadores.fiscal', $uuid)
            ->with('exito', 'Perfil aprobado y vigente. Avisa al creador por su canal de contacto anterior: '
                .'BR-CREATOR-007 lo exige y todavía no hay envío automático (T-10).');
    }

    public function rechazar(Request $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $perfil = $this->perfilDe($creador, $id);

        if ($perfil->status !== 'pending') {
            return back()->with('aviso', "Este perfil ya está en «{$perfil->status}».");
        }

        $datos = $request->validate([
            // Un rechazo sin motivo no se le puede explicar al creador, y este
            // rechazo es el que le impide cobrar.
            'rejection_note' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        // `approved_by_user_id` NO se toca al rechazar, y esto tiene historia.
        //
        // La primera version lo escribia —parecia natural: «quien resolvio»—, y
        // eso reventaba con un error 4025 en cuanto el propio capturador queria
        // retirar una captura equivocada suya: `ck_ctp_segregation` compara esa
        // columna con `created_by_user_id` sea cual sea el estado. Se reprodujo
        // en SQL antes de tocar nada.
        //
        // La restriccion existe para impedir la AUTOAPROBACION, no para impedir
        // que alguien corrija su propio error, que no hace dano a nadie. Asi que
        // el arreglo no es relajar la restriccion: es dejar de usar una columna
        // que se llama «aprobado por» para registrar un rechazo.
        //
        // Quien rechazo queda en la bitacora, con nombre y correo congelados y
        // sin posibilidad de reescritura. Para esto es mejor evidencia que una
        // clave foranea que se puede actualizar. Ver H-04 en docs/fase-3/3.6.
        DB::table('creator_tax_profiles')->where('id', $perfil->id)->update([
            'status' => 'rejected',
            'rejection_note' => $datos['rejection_note'],
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_tax_profile.rejected',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'perfil_fiscal' => ['antes' => null, 'despues' => $perfil->id],
                'status' => ['antes' => 'pending', 'despues' => 'rejected'],
                'motivo' => ['antes' => null, 'despues' => $datos['rejection_note']],
            ],
        );

        return redirect()->route('creadores.fiscal', $uuid)->with('exito', 'Perfil rechazado y registrado.');
    }

    // ------------------------------------------------------------------ apoyo

    private function perfilDe(object $creador, int $id): object
    {
        $perfil = DB::table('creator_tax_profiles')
            ->where('id', $id)
            ->where('creator_id', $creador->id)
            ->first();

        if ($perfil === null) {
            throw new NotFoundHttpException('Perfil fiscal no encontrado para este creador.');
        }

        return $perfil;
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
