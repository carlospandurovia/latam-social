<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Campaign\Services\Invitaciones;
use App\Shared\Http\EnlaceEnSesion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La pantalla del creador para contestar una invitación (7.6).
 *
 * ### Es la primera pantalla del sistema hecha para alguien de fuera
 *
 * Y eso cambia dos cosas. La primera es **qué se enseña**: la campaña, la marca,
 * las fechas, **su** importe y su plazo de pago. Nada más. Ni lo que cobra el
 * cliente, ni lo que cobran los demás creadores, ni el presupuesto, ni el margen
 * — `BR-SEC-001` es 🔴 y dice exactamente eso.
 *
 * La segunda es **qué pasa cuando algo falla**. Aquí no hay un operador que sepa
 * leer un mensaje técnico: hay una persona que abrió un correo. Cada motivo por el
 * que un enlace no sirve tiene su texto y dice qué hacer a continuación.
 *
 * ### Sin sesión, sin cuenta y sin portal
 *
 * El creador no necesita entrar. Desde `5.9` tiene cuenta, pero su portal (`F6`)
 * está bloqueado por `T-09`, y exigirle que ponga una contraseña para contestar
 * una invitación sería poner una puerta delante de la única cosa que queremos que
 * haga.
 *
 * La autorización **es el token**, y vale una sola vez.
 */
final class InvitacionController
{
    use EnlaceEnSesion;

    /** Abre el enlace del correo: valida, guarda el token y redirige sin él. */
    public function ver(Request $peticion, string $token): RedirectResponse
    {
        $resultado = Invitaciones::validar($token);

        if (!$resultado['ok']) {
            return redirect()->route('invitacion.caducada')
                ->with('fallo', Invitaciones::FALLOS[$resultado['motivo']] ?? 'Esta invitacion no sirve.');
        }

        // Que la abrio consta antes de que decida nada. Alimenta la pregunta de
        // «.lo leyo siquiera?», que no es la misma que «.contesto?».
        Invitaciones::marcarAbierta((int) $resultado['invitacion']->id);

        $this->guardarToken($peticion, $token);

        return redirect()->route('invitacion.oferta');
    }

    public function oferta(Request $peticion): View|RedirectResponse
    {
        $resultado = $this->invitacionDeSesion($peticion);

        if (!$resultado['ok']) {
            return $this->alFallo($peticion, $resultado['motivo']);
        }

        return view('invitacion.oferta', [
            'i' => $resultado['invitacion'],
            'motivos' => Invitaciones::MOTIVOS,
        ]);
    }

    public function aceptar(Request $peticion): RedirectResponse
    {
        $token = $this->tokenDeSesion($peticion);

        if ($token === '') {
            return $this->alFallo($peticion, 'sesion_perdida');
        }

        $resultado = Invitaciones::aceptar($token, $peticion->ip());
        $this->olvidarToken($peticion);

        if (!$resultado['ok']) {
            return redirect()->route('invitacion.caducada')
                ->with('fallo', Invitaciones::FALLOS[$resultado['motivo']] ?? 'Esta invitacion no sirve.');
        }

        return redirect()->route('invitacion.gracias')->with('respuesta', 'accepted');
    }

    public function rechazar(Request $peticion): RedirectResponse
    {
        $token = $this->tokenDeSesion($peticion);

        if ($token === '') {
            return $this->alFallo($peticion, 'sesion_perdida');
        }

        $datos = $peticion->validate([
            // El motivo es obligatorio y de lista cerrada. Un rechazo sin motivo
            // no se puede sumar, y de sumarlos sale la unica pregunta util:
            // «.por que nos dicen que no?».
            'motivo' => ['required', 'string', 'in:'.implode(',', array_keys(Invitaciones::MOTIVOS))],
            'nota' => ['nullable', 'string', 'max:255'],
        ], [
            'motivo.required' => 'Elige un motivo, aunque sea «otro».',
            'motivo.in' => 'Ese motivo no es de la lista.',
        ]);

        $resultado = Invitaciones::rechazar(
            $token,
            (string) $datos['motivo'],
            isset($datos['nota']) ? trim((string) $datos['nota']) : null,
            $peticion->ip(),
        );
        $this->olvidarToken($peticion);

        if (!$resultado['ok']) {
            return redirect()->route('invitacion.caducada')
                ->with('fallo', Invitaciones::FALLOS[$resultado['motivo']] ?? 'Esta invitacion no sirve.');
        }

        return redirect()->route('invitacion.gracias')->with('respuesta', 'declined');
    }

    public function gracias(Request $peticion): View|RedirectResponse
    {
        $respuesta = (string) $peticion->session()->get('respuesta', '');

        if ($respuesta === '') {
            return redirect()->route('invitacion.caducada')
                ->with('fallo', Invitaciones::FALLOS['no_existe']);
        }

        return view('invitacion.gracias', ['acepto' => $respuesta === 'accepted']);
    }

    /** La pantalla de «esto ya no sirve», con su motivo. */
    public function caducada(): View
    {
        return view('invitacion.caducada');
    }

    // ------------------------------------------------------------------ apoyo

    protected function claveDeSesion(): string
    {
        return 'enlace_invitacion';
    }

    /** @return array{ok: bool, motivo: ?string, invitacion: ?object} */
    private function invitacionDeSesion(Request $peticion): array
    {
        $token = $this->tokenDeSesion($peticion);

        // «No hay token en la sesion» y «el token no vale» no son lo mismo, y la
        // persona tiene que hacer algo distinto en cada caso: volver a abrir el
        // correo, o escribirnos.
        return $token === ''
            ? ['ok' => false, 'motivo' => 'sesion_perdida', 'invitacion' => null]
            : Invitaciones::validar($token);
    }

    private function alFallo(Request $peticion, ?string $motivo): RedirectResponse
    {
        $this->olvidarToken($peticion);

        return redirect()->route('invitacion.caducada')->with(
            'fallo',
            $motivo === 'sesion_perdida'
                ? 'Se perdio el rastro en este navegador. Vuelve a abrir el enlace del correo.'
                : (Invitaciones::FALLOS[$motivo] ?? 'Esta invitacion no sirve.'),
        );
    }
}
