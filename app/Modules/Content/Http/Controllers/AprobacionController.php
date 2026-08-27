<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\ResponderAprobacionRequest;
use App\Modules\Content\Services\Aprobaciones;
use App\Shared\Http\EnlaceEnSesion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La pantalla del cliente para dar su visto bueno (8.5).
 *
 * ### La segunda pantalla del sistema hecha para alguien de fuera
 *
 * La primera fue la invitación del creador (`7.6`), y esto hereda sus dos
 * lecciones.
 *
 * **Qué se enseña.** La campaña, la marca, el formato, quién lo hizo y la pieza.
 * Nada más. Ni el importe del creador, ni el presupuesto, ni el margen, ni las
 * otras piezas de la campaña — `BR-SEC-001` es 🔴 y dice exactamente eso. La
 * frontera está en `Aprobaciones::pieza()`, que enumera columnas en vez de
 * traerse la fila entera.
 *
 * **Qué pasa cuando algo falla.** Aquí no hay un operador que sepa leer un
 * mensaje técnico: hay una persona que abrió un correo. Cada motivo por el que
 * un enlace no sirve tiene su texto y dice qué hacer a continuación.
 *
 * ### Su respuesta no mueve la pieza (`DEC-151`)
 *
 * Se registra con su hora y su IP, y la cierra alguien del equipo desde la cola
 * de revisión. La pantalla se lo dice con esas palabras, porque un cliente que
 * pulsa «me vale» y no ve nada moverse tiene derecho a saber qué pasa después.
 */
final class AprobacionController
{
    use EnlaceEnSesion;

    /** Abre el enlace del correo: valida, guarda el token y redirige sin él. */
    public function ver(Request $peticion, string $token): RedirectResponse
    {
        $resultado = Aprobaciones::validar($token);

        if (!$resultado['ok']) {
            return redirect()->route('aprobacion.caducada')
                ->with('fallo', Aprobaciones::FALLOS[$resultado['motivo']] ?? 'Este enlace no sirve.');
        }

        Aprobaciones::marcarAbierto((int) $resultado['enlace']->id);

        // `DEC-117`: el token no se queda en la barra de direcciones.
        $this->guardarToken($peticion, $token);

        return redirect()->route('aprobacion.pieza');
    }

    public function pieza(Request $peticion): View|RedirectResponse
    {
        $resultado = Aprobaciones::validar($this->tokenDeSesion($peticion));

        if (!$resultado['ok']) {
            $this->olvidarToken($peticion);

            return redirect()->route('aprobacion.caducada')
                ->with('fallo', Aprobaciones::FALLOS[$resultado['motivo']] ?? 'Este enlace no sirve.');
        }

        /** @var \stdClass $enlace */
        $enlace = $resultado['enlace'];

        return view('aprobacion.pieza', [
            'pieza' => Aprobaciones::pieza($enlace),
            'caduca' => $enlace->expires_at,
        ]);
    }

    public function responder(ResponderAprobacionRequest $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        $resultado = Aprobaciones::responder(
            $this->tokenDeSesion($peticion),
            (string) $datos['respuesta'],
            $datos['comentario'] ?? null,
            $peticion->ip(),
        );

        // Salga bien o mal: un token que sobrevive a su intento es una segunda
        // oportunidad que no deberia existir.
        $this->olvidarToken($peticion);

        if (!$resultado['ok']) {
            return redirect()->route('aprobacion.caducada')
                ->with('fallo', Aprobaciones::FALLOS[$resultado['motivo']] ?? 'Este enlace no sirve.');
        }

        return redirect()->route('aprobacion.gracias')
            ->with('respuesta', (string) $datos['respuesta']);
    }

    public function gracias(): View
    {
        return view('aprobacion.gracias', ['respuesta' => session('respuesta')]);
    }

    public function caducada(): View
    {
        return view('aprobacion.caducada', ['fallo' => session('fallo')]);
    }

    protected function claveDeSesion(): string
    {
        return 'aprobacion_token';
    }
}
