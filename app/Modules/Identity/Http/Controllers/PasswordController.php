<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\CambiarPasswordRequest;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Cambiar la propia contraseña (`T-23`).
 *
 * ### Por qué esto no era opcional
 *
 * `usuarios:crear` escribía `must_change_password = 1` desde la primera
 * iteración de identidad, y **nadie lo leía nunca**: no había middleware que lo
 * comprobara ni pantalla donde cambiarla. La única aparición de la columna en
 * todo el árbol era esa escritura.
 *
 * O sea que el administrador que da de alta a la persona de finanzas teclea su
 * contraseña, se la dice, y esa contraseña sigue siendo válida indefinidamente.
 *
 * Eso no es sólo higiene. La base de datos **exige dos personas distintas** para
 * lo que toca dinero —`ck_ctp_segregation` para aprobar un perfil fiscal,
 * `ck_cpm_segregation` para verificar un medio de pago (`DEC-044`,
 * `BR-FIN-005`)—. Esa garantía se apoya en que dos `user_id` distintos sean dos
 * personas distintas. Si el admin conoce la credencial de la segunda, la
 * separación es una columna en una tabla y nada más.
 *
 * ### Qué pasa después de cambiarla
 *
 * Se rota el identificador de sesión (`Auth::logoutOtherDevices` necesitaría la
 * contraseña en claro y ya no la tenemos aquí; lo que sí se hace es regenerar
 * la sesión actual). Y se anota en la bitácora **que** se cambió, nunca a qué:
 * `Bitacora::REDACTAR` ya oculta cualquier campo cuyo nombre contenga
 * `password`.
 */
final class PasswordController
{
    public function formulario(): View
    {
        $usuario = Auth::user();

        return view('acceso.password', [
            // Si es obligatorio, la pantalla lo dice y no ofrece salida: el
            // middleware va a devolver aquí a cualquier otra ruta.
            'obligatorio' => (bool) ($usuario->must_change_password ?? false),
        ]);
    }

    public function cambiar(CambiarPasswordRequest $request): RedirectResponse
    {
        $usuario = $request->user();

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        // `forceFill` + `save` y no un `update` suelto: pasa por el modelo, que
        // es quien sabe que `password` va cifrada.
        $usuario->forceFill([
            'password' => Hash::make((string) $datos['password']),
            'must_change_password' => false,
        ])->save();

        // La sesión se regenera: si alguien tenía capturado el identificador de
        // sesión anterior, deja de servirle.
        $request->session()->regenerate();

        Bitacora::registrar(
            accion: 'user.password_changed',
            tipoEntidad: 'user',
            idEntidad: (int) $usuario->getAuthIdentifier(),
            // Sólo el hecho. `REDACTAR` ocultaría el valor de todas formas
            // —cualquier campo con `password` en el nombre— pero no se manda
            // ni para que lo oculte.
            cambios: ['password' => ['antes' => null, 'despues' => null]],
        );

        return redirect()->route('panel')->with('exito', 'Contrasena cambiada.');
    }

    /**
     * ¿Hay usuarios internos con la contraseña temporal todavía puesta?
     *
     * No es una pantalla: lo usa el panel para avisar al administrador. Una
     * cuenta creada hace tres meses que nunca cambió su contraseña es una
     * credencial que dos personas conocen.
     */
    public static function pendientesDeCambio(): int
    {
        return DB::table('users')
            ->where('must_change_password', 1)
            ->where('status', 'active')
            ->count();
    }
}
