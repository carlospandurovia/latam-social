<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea un usuario interno y le asigna un rol. Desbloquea `B-2`.
 *
 * **Por qué existe.** `UsuarioAdminSeeder` crea uno solo, y con uno solo el
 * sistema está bloqueado por diseño: `ck_ctp_segregation` exige que quien
 * aprueba un perfil tributario no sea quien lo capturó, y `ck_cpm_segregation`
 * lo mismo para las cuentas bancarias. Con un único usuario **ningún creador
 * puede activarse**. No es un fallo: es la separación de funciones haciendo su
 * trabajo. Lo que faltaba era una forma cómoda de darle el segundo actor.
 *
 * **Por qué un comando y no una pantalla.** Por lo mismo que
 * `terminos:publicar`: dar de alta usuarios internos es un acto raro y de alto
 * privilegio. Una pantalla para eso, hoy, es superficie de ataque a cambio de
 * nada. Cuando el equipo crezca habrá una, con su permiso y su bitácora.
 *
 * **La contraseña no se pasa por argumento**, y esto no es celo: un argumento
 * queda en el historial de la consola, y de ahí pasa a los respaldos de la
 * máquina. Se pregunta oculta, o se genera y se enseña una vez.
 *
 *   php artisan usuarios:crear "Ana Aprobadora" ana@cts.pe --rol=finance
 *   php artisan usuarios:crear "Ana" ana@cts.pe --rol=finance --generar
 */
final class CrearUsuarioInternoCommand extends Command
{
    protected $signature = 'usuarios:crear
        {nombre : Nombre completo}
        {email : Correo, que es con lo que entra}
        {--rol=finance : admin, campaign_manager, finance o content_reviewer}
        {--generar : Genera una contrasena aleatoria y la muestra una sola vez}';

    protected $description = 'Crea un usuario interno y le asigna un rol (desbloquea B-2).';

    public function handle(): int
    {
        $nombre = trim((string) $this->argument('nombre'));
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $rol = (string) $this->option('rol');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("«{$email}» no es un correo valido.");

            return self::FAILURE;
        }

        $rolId = DB::table('roles')->where('code', $rol)->where('scope', 'internal')->value('id');

        if ($rolId === null) {
            $this->error("No existe el rol interno «{$rol}».");
            $this->line('Roles internos: '.implode(', ', DB::table('roles')
                ->where('scope', 'internal')->orderBy('code')->pluck('code')->all()));

            return self::FAILURE;
        }

        // `uq_users_email_active` solo mira los usuarios activos: un correo
        // puede repetirse si el anterior esta desactivado. Aqui se comprueba lo
        // mismo que la base, para poder decirlo con palabras en vez de con un
        // error 1062.
        $repetido = DB::table('users')->where('email', $email)->where('status', 'active')->exists();

        if ($repetido) {
            $this->error("Ya hay un usuario ACTIVO con el correo {$email}.");

            return self::FAILURE;
        }

        $clave = $this->option('generar')
            ? Str::password(16)
            : (string) $this->secret('Contrasena (no se ve al teclear)');

        if (mb_strlen($clave) < 12) {
            $this->error('La contrasena tiene que tener al menos 12 caracteres.');

            return self::FAILURE;
        }

        $id = DB::transaction(function () use ($nombre, $email, $clave, $rolId): int {
            $nuevo = (int) DB::table('users')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $nombre,
                'email' => $email,
                // Nunca en claro, y con el algoritmo que decida la aplicacion,
                // no uno escrito a mano aqui.
                'password' => Hash::make($clave),
                'user_type' => 'internal',
                'status' => 'active',
                // Quien la teclea aqui no es el dueno de la cuenta.
                'must_change_password' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('role_user')->insert([
                'user_id' => $nuevo,
                'role_id' => $rolId,
                'assigned_at' => now(),
            ]);

            return $nuevo;
        });

        $this->info("Usuario {$id} creado: {$nombre} <{$email}> con rol «{$rol}».");
        $this->line('Tiene que cambiar la contrasena la primera vez que entre.');

        if ($this->option('generar')) {
            $this->newLine();
            $this->warn('Contrasena generada. Se muestra UNA vez y no queda en claro en ningun sitio:');
            $this->line('    '.$clave);
        }

        $internos = DB::table('users')->where('user_type', 'internal')->where('status', 'active')->count();

        if ($internos >= 2) {
            $this->newLine();
            $this->info("Hay {$internos} usuarios internos activos: B-2 deja de bloquear. "
                .'Ya se pueden aprobar perfiles fiscales y verificar medios de pago.');
        }

        return self::SUCCESS;
    }
}
