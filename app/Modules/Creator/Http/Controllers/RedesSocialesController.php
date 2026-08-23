<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\GuardarCuentaSocialRequest;
use App\Modules\Creator\Http\Requests\RegistrarMetricaRequest;
use App\Modules\Creator\Http\Requests\VerificarCuentaSocialRequest;
use App\Modules\Creator\Services\CoherenciaMetrica;
use App\Shared\Audit\Bitacora;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las cuentas sociales del creador y su histórico de métricas (iteración 3.7).
 *
 * Cierra el cuarto de los seis requisitos de `BR-CREATOR-006`: al menos una red
 * social validada.
 *
 * Cuatro cosas que no son evidentes:
 *
 * 1. **Dar de alta y verificar son dos actos.** Que el creador diga que una
 *    cuenta es suya no la hace suya. El alta pide `creator.manage`; la
 *    verificación, `creator.verify` — el mismo permiso con el que se coteja un
 *    documento de identidad, porque es el mismo tipo de acto.
 *
 * 2. **Verificar exige decir cómo y quién** (`H-05`). La base lo impone con
 *    `ck_social_accounts_evidence` y `ck_social_accounts_verifier`.
 *
 * 3. **El estado de coherencia no se elige, se calcula.** Si lo eligiera quien
 *    teclea, marcaría «limpio» siempre y volveríamos al cero que mentía
 *    (`H-06`). Lo decide `CoherenciaMetrica`, y **nunca rechaza**: marca para
 *    revisión humana, que es lo que dice `BR-CREATOR-004`.
 *
 * 4. **Nada se corrige, todo se acumula.** `social_account_snapshots` es de
 *    solo inserción y desde 3.7 lo impiden dos disparadores, no una convención
 *    (`H-07`). Una métrica equivocada se arregla capturando la buena encima.
 */
final class RedesSocialesController
{
    public function index(string $uuid): View
    {
        $creador = $this->porUuid($uuid);

        $cuentas = DB::table('social_accounts as sa')
            ->join('platforms as pl', 'pl.id', '=', 'sa.platform_id')
            ->leftJoin('users as u', 'u.id', '=', 'sa.verified_by_user_id')
            ->where('sa.creator_id', $creador->id)
            ->orderByDesc('sa.is_primary')
            ->orderBy('pl.name')
            ->get([
                'sa.id', 'sa.handle', 'sa.profile_url', 'sa.verification_status',
                'sa.verification_method', 'sa.verified_at', 'sa.is_primary', 'sa.is_active',
                'pl.name as red', 'u.name as verificada_por',
            ]);

        // El histórico completo, agrupado en PHP: son pocas filas por creador y
        // así la vista no dispara una consulta por cuenta.
        $historico = DB::table('social_account_snapshots as sn')
            ->join('social_accounts as sa', 'sa.id', '=', 'sn.social_account_id')
            ->where('sa.creator_id', $creador->id)
            ->orderByDesc('sn.captured_at')
            ->orderByDesc('sn.id')
            ->get([
                'sn.social_account_id', 'sn.captured_at', 'sn.source', 'sn.followers',
                'sn.engagement_rate', 'sn.coherence_status', 'sn.anomaly_note',
            ])
            ->groupBy('social_account_id');

        return view('creadores.redes', [
            'creador' => $creador,
            'cuentas' => $cuentas,
            'historico' => $historico,
            'redes' => DB::table('platforms')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(GuardarCuentaSocialRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        // BR-CREATOR-003: se avisa ANTES de chocar. `uq_social_accounts_verified`
        // solo salta al VERIFICAR, así que sin esto el operador daría de alta la
        // cuenta, intentaría verificarla y solo entonces descubriría que es de
        // otro creador — con el trabajo ya hecho.
        $deOtro = DB::table('social_accounts as sa')
            ->join('creators as cr', 'cr.id', '=', 'sa.creator_id')
            ->where('sa.platform_id', $datos['platform_id'])
            ->where('sa.handle', $datos['handle'])
            ->where('sa.verification_status', 'verified')
            ->where('sa.creator_id', '<>', $creador->id)
            ->first(['cr.display_name', 'cr.uuid']);

        if ($deOtro !== null) {
            return back()->withInput()->with('aviso',
                "Esa cuenta ya está verificada a nombre de «{$deOtro->display_name}». "
                .'Si de verdad es de este creador, hay que resolver primero cuál de los dos la tiene (BR-CREATOR-003).');
        }

        $id = (int) DB::table('social_accounts')->insertGetId($datos + [
            'uuid' => (string) Str::uuid(),
            'creator_id' => $creador->id,
            // Nace sin verificar. Decirlo es el alta; comprobarlo es otro acto.
            'verification_status' => 'unverified',
            'is_primary' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'social_account.created',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'cuenta' => ['antes' => null, 'despues' => $id],
                'handle' => ['antes' => null, 'despues' => $datos['handle']],
            ],
        );

        return redirect()->route('creadores.redes', $uuid)
            ->with('exito', 'Cuenta registrada, sin verificar. Comprobar que es suya es el siguiente paso.');
    }

    public function verificar(VerificarCuentaSocialRequest $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $cuenta = $this->cuentaDe($creador, $id);

        if ($cuenta->verification_status === 'verified') {
            return back()->with('aviso', 'Esta cuenta ya está verificada.');
        }

        $choque = DB::table('social_accounts')
            ->where('platform_id', $cuenta->platform_id)
            ->where('handle', $cuenta->handle)
            ->where('verification_status', 'verified')
            ->where('id', '<>', $cuenta->id)
            ->exists();

        if ($choque) {
            return back()->with('aviso', 'Otro creador ya tiene esa misma cuenta verificada (BR-CREATOR-003).');
        }

        $metodo = (string) $request->input('verification_method');

        DB::table('social_accounts')->where('id', $cuenta->id)->update([
            'verification_status' => 'verified',
            'verification_method' => $metodo,
            'verified_by_user_id' => $request->user()?->getAuthIdentifier(),
            'verified_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'social_account.verified',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'cuenta' => ['antes' => null, 'despues' => $cuenta->id],
                'handle' => ['antes' => null, 'despues' => $cuenta->handle],
                'metodo' => ['antes' => null, 'despues' => $metodo],
                'nota_del_revisor' => ['antes' => null, 'despues' => $request->input('nota')],
            ],
        );

        return redirect()->route('creadores.redes', $uuid)
            ->with('exito', 'Cuenta verificada. Queda registrado el método y quién lo hizo.');
    }

    public function registrarMetrica(RegistrarMetricaRequest $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $cuenta = $this->cuentaDe($creador, $id);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        // El estado NO viene del formulario: se calcula. Y se calcula ANTES de
        // insertar, comparando contra el último snapshot, que todavía es el
        // anterior.
        // `?? null` y no acceso directo: `validated()` solo trae las claves que
        // vinieron, y un campo opcional en blanco simplemente no viene.
        $seguidores = $datos['followers'] ?? null;
        $tasa = $datos['engagement_rate'] ?? null;

        // Misma razón que en la aceptación de términos: el campo del navegador
        // manda «2026-08-23T10:00» y esta tabla no admite correcciones después.
        $datos['captured_at'] = CarbonImmutable::parse((string) $datos['captured_at'])->format('Y-m-d H:i:s.v');

        $veredicto = CoherenciaMetrica::evaluar((int) $cuenta->id, [
            'followers' => $seguidores === null ? null : (int) $seguidores,
            'engagement_rate' => $tasa === null ? null : (float) $tasa,
            'captured_at' => $datos['captured_at'],
        ]);

        DB::table('social_account_snapshots')->insert($datos + [
            'social_account_id' => $cuenta->id,
            'coherence_status' => $veredicto['estado'],
            'anomaly_note' => CoherenciaMetrica::nota($veredicto['motivos']),
        ]);

        Bitacora::registrar(
            accion: 'social_account.metric_captured',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'cuenta' => ['antes' => null, 'despues' => $cuenta->id],
                'seguidores' => ['antes' => null, 'despues' => $seguidores],
                'coherencia' => ['antes' => null, 'despues' => $veredicto['estado']],
            ],
        );

        if ($veredicto['motivos'] !== []) {
            return redirect()->route('creadores.redes', $uuid)
                ->with('aviso', 'Métrica guardada y marcada para revisión: '
                    .implode('; ', $veredicto['motivos']).'. No se rechaza nada (BR-CREATOR-004).');
        }

        return redirect()->route('creadores.redes', $uuid)
            ->with('exito', 'Métrica guardada. Pasó los chequeos de coherencia.');
    }

    // ------------------------------------------------------------------ apoyo

    private function cuentaDe(object $creador, int $id): object
    {
        $cuenta = DB::table('social_accounts')
            ->where('id', $id)
            ->where('creator_id', $creador->id)
            ->first();

        if ($cuenta === null) {
            throw new NotFoundHttpException('Cuenta social no encontrada para este creador.');
        }

        return $cuenta;
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
