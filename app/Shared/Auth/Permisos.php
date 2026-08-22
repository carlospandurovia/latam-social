<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Responde a una sola pregunta: ¿este usuario tiene este permiso?
 *
 * Tres decisiones que conviene no deshacer:
 *
 * 1. **El código pregunta por el permiso, nunca por el rol** (docs/08). Un
 *    `if ($usuario->esAdmin())` esparcido por los controladores convierte cada
 *    cambio de política en una cacería por el repositorio. Los roles son datos;
 *    los permisos son el contrato.
 *
 * 2. **No hay atajo para el administrador.** Sería cómodo un
 *    `Gate::before(fn ($u) => $u->esAdmin() ? true : null)`, pero eso abre un
 *    agujero que ninguna prueba detecta: el rol tendría, en silencio, permisos
 *    que nadie le concedió — incluidos los que se inventen mañana. Aquí `admin`
 *    tiene todos los permisos **como datos**, sembrados explícitamente en
 *    `permission_role`, y la comprobación no tiene ningún caso especial. Así
 *    «quién puede hacer qué» se responde con una consulta, no leyendo código.
 *
 * 3. **Sin Eloquent y sin depender de `App\Models\User`.** `app/Models` no
 *    pertenece a ninguna capa de Deptrac, así que una dependencia desde `Shared`
 *    hacia el modelo sería una dependencia sin cubrir y rompería el CI. Se
 *    trabaja con el identificador, que es lo único que hace falta.
 */
final class Permisos
{
    /**
     * Caché por proceso. Una petición pregunta por varios permisos (middleware,
     * menú, vista) y sería absurdo consultar la base cada vez.
     *
     * @var array<int, array<string, true>>
     */
    private static array $cache = [];

    /**
     * Los códigos de permiso de un usuario, por sus roles.
     *
     * @return array<string, true> el código como clave: la pertenencia es O(1)
     */
    public static function de(int $usuarioId): array
    {
        if (isset(self::$cache[$usuarioId])) {
            return self::$cache[$usuarioId];
        }

        $codigos = DB::table('permissions as p')
            ->join('permission_role as pr', 'pr.permission_id', '=', 'p.id')
            ->join('role_user as ru', 'ru.role_id', '=', 'pr.role_id')
            ->where('ru.user_id', $usuarioId)
            ->distinct()
            ->pluck('p.code')
            ->all();

        $mapa = [];
        foreach ($codigos as $codigo) {
            if (is_string($codigo)) {
                $mapa[$codigo] = true;
            }
        }

        return self::$cache[$usuarioId] = $mapa;
    }

    public static function tiene(int $usuarioId, string $permiso): bool
    {
        return isset(self::de($usuarioId)[$permiso]);
    }

    /**
     * ¿Tiene ALGUNO de estos? Es la semántica del middleware `permiso:a,b`.
     *
     * @param list<string> $permisos
     */
    public static function tieneAlguno(int $usuarioId, array $permisos): bool
    {
        $suyos = self::de($usuarioId);
        foreach ($permisos as $permiso) {
            if (isset($suyos[$permiso])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vacía la caché. Obligatorio tras cambiar roles o permisos de un usuario, y
     * entre pruebas: si no, la segunda prueba ve los permisos de la primera.
     */
    public static function olvidar(?int $usuarioId = null): void
    {
        if ($usuarioId === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$usuarioId]);
    }
}
