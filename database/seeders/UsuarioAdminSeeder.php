<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea el primer usuario interno para poder entrar al panel.
 *
 * La contraseña NO va escrita en el repositorio: se toma de ADMIN_PASSWORD del
 * .env, y si no está, se genera una al azar y se imprime UNA sola vez. Una
 * contraseña por defecto en un seeder termina siempre en producción.
 *
 * Se lee por `config('latam.admin.*')` y NO por `env()` directamente: fuera de
 * `config/`, `env()` devuelve null en cuanto se ejecuta `php artisan
 * config:cache`. Con la configuración cacheada, este seeder ignoraba la
 * contraseña del operador y generaba una al azar sin decir nada.
 */
final class UsuarioAdminSeeder extends Seeder
{
    public function run(): void
    {
        $correo = (string) config('latam.admin.correo');
        $existe = DB::table('users')->where('email', $correo)->first();

        if ($existe !== null) {
            $this->command?->warn("El usuario {$correo} ya existe. No se toca.");
            $this->asignarRol((int) $existe->id);

            return;
        }

        $clave = config('latam.admin.clave');
        $clave = is_string($clave) && $clave !== '' ? $clave : null;
        $generada = $clave === null;
        $clave ??= Str::password(16);

        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => (string) config('latam.admin.nombre'),
            'email' => $correo,
            'password' => Hash::make($clave),
            'user_type' => 'internal',
            'status' => 'active',
            'locale' => 'es',
            'timezone' => 'America/Lima',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->asignarRol($id);

        $this->command?->newLine();
        $this->command?->info("Usuario creado: {$correo}");
        if ($generada) {
            $this->command?->warn("Contraseña generada: {$clave}");
            $this->command?->warn('Guárdala ahora: no se vuelve a mostrar.');
        } else {
            $this->command?->line('Contraseña: la de ADMIN_PASSWORD en tu .env');
        }
        $this->command?->newLine();
    }

    private function asignarRol(int $usuarioId): void
    {
        $rolId = DB::table('roles')->where('code', 'admin')->value('id');
        if ($rolId === null) {
            return;
        }

        DB::table('role_user')->updateOrInsert(
            ['user_id' => $usuarioId, 'role_id' => $rolId],
            ['assigned_at' => now()],
        );
    }
}
