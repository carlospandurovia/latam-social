<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AccesoController
{
    public function formulario(): View
    {
        return view('auth.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // El mensaje es el mismo tanto si el correo no existe como si la
        // contraseña falla: distinguirlos permite averiguar qué correos
        // están dados de alta.
        if (!Auth::attempt($datos, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        $usuario = Auth::user();

        // Un usuario desactivado o suspendido no entra, aunque su contraseña
        // sea válida (BR-SEC / BR-CREATOR-001 aplicado a usuarios internos).
        if (($usuario->status ?? 'active') !== 'active') {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'Esta cuenta no está activa. Contacta con administración.',
            ]);
        }

        $request->session()->regenerate();
        $usuario->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('panel'));
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('acceso');
    }
}
