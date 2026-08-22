<?php

declare(strict_types=1);

namespace App\Shared\Providers;

use App\Shared\Auth\Permisos;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Conecta los permisos con `Gate`, para que las vistas puedan usar
 * `@can('creator.view')` y el menú no muestre enlaces que van a devolver 403.
 *
 * Se resuelve con `Gate::before` y no definiendo un gate por permiso, porque
 * definirlos exigiría leer la tabla `permissions` en cada arranque —incluso
 * durante `migrate`, cuando todavía no existe—.
 *
 * El detalle que importa: `before` devuelve **`true` o `null`, nunca `false`**.
 * Un `false` en `before` deniega TODAS las autorizaciones del sistema, incluidas
 * las que nada tienen que ver con permisos. Devolviendo `null` la evaluación
 * continúa, y como no hay ningún gate definido para esa habilidad, Laravel
 * deniega igualmente. Mismo resultado, sin efecto colateral.
 */
final class AutorizacionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (Authenticatable $usuario, string $habilidad): ?bool {
            // Los permisos son `modulo.accion`. Una habilidad sin punto es otra
            // cosa (una policy, por ejemplo) y no le corresponde a esto.
            if (!str_contains($habilidad, '.')) {
                return null;
            }

            $id = $usuario->getAuthIdentifier();

            if (!is_int($id) && !is_string($id)) {
                return null;
            }

            return Permisos::tiene((int) $id, $habilidad) ? true : null;
        });
    }
}
