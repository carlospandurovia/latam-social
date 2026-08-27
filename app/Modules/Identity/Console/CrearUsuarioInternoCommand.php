<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Services\Cuentas;
use App\Modules\Identity\Services\EnlacesDeContrasena;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea un usuario interno y le manda su enlace de contraseña. Cierra `T-36`.
 *
 * **Por qué existe.** `UsuarioAdminSeeder` crea uno solo, y con uno solo el
 * sistema está bloqueado por diseño: `ck_ctp_segregation` exige que quien
 * aprueba un perfil tributario no sea quien lo capturó, y `ck_cpm_segregation`
 * lo mismo para las cuentas bancarias. Con un único usuario **ningún creador
 * puede activarse**. No es un fallo: es la separación de funciones haciendo su
 * trabajo. Lo que faltaba era una forma cómoda de darle el segundo actor.
 *
 * ### La contraseña ya no se teclea aquí, y eso es el cambio
 *
 * Hasta `T-36` este comando la pedía —oculta, o generada— y alguien se la
 * dictaba a su dueño. `must_change_password` obligaba a cambiarla después, y eso
 * dejaba una ventana —de minutos o de meses— **en la que dos personas conocían
 * la credencial**.
 *
 * Justo en estas cuentas. La garantía de que dos `user_id` distintos son dos
 * personas distintas es lo que sostiene `BR-FIN-005` en la base; si el
 * administrador conoce la credencial de la segunda, esa garantía es una fila en
 * una tabla y nada más.
 *
 * Ahora la cuenta nace con el hash de 32 bytes aleatorios que no se guardan, no
 * se muestran y no se devuelven, y su dueño elige la contraseña desde un enlace
 * de 72 h. **No hay ventana**: la contraseña no existe hasta que la escribe él.
 *
 * **Por qué un comando y no una pantalla.** Por lo mismo que
 * `terminos:publicar`: dar de alta usuarios internos es un acto raro y de alto
 * privilegio. Una pantalla para eso, hoy, es superficie de ataque a cambio de
 * nada. Cuando el equipo crezca habrá una, con su permiso y su bitácora.
 *
 *   php artisan usuarios:crear "Ana Aprobadora" ana@cts.pe --rol=finance
 */
final class CrearUsuarioInternoCommand extends Command
{
    protected $signature = 'usuarios:crear
        {nombre : Nombre completo}
        {email : Correo, que es con lo que entra}
        {--rol=finance : admin, campaign_manager, finance o content_reviewer}';

    protected $description = 'Crea un usuario interno y le manda su enlace de contrasena (desbloquea B-2).';

    public function handle(): int
    {
        $nombre = trim((string) $this->argument('nombre'));
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $rol = (string) $this->option('rol');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("«{$email}» no es un correo valido.");

            return self::FAILURE;
        }

        if (($fallo = $this->rolInvalido($rol)) !== null) {
            return $fallo;
        }

        $cuenta = Cuentas::paraInterno($email, $nombre, $rol);

        if ($cuenta['usuarioId'] === null) {
            $this->error(Cuentas::explicacion((string) $cuenta['motivo']));

            return self::FAILURE;
        }

        $horas = EnlacesDeContrasena::HORAS['initial'];

        $this->info("Usuario {$cuenta['usuarioId']} creado: {$nombre} <{$email}> con rol «{$rol}».");
        $this->newLine();
        $this->line("Se le ha mandado un enlace para que elija su contrasena. Vale {$horas} horas");
        $this->line('y solo se puede usar una vez. Hasta entonces la cuenta no tiene contrasena');
        $this->line('valida: no puede entrar nadie, tampoco quien la acaba de crear.');

        // Sin SMTP el enlace no sale del servidor, y sin decirlo el
        // administrador se queda esperando un correo que no va a llegar.
        // `Q-20` sigue abierta y esto es lo que se nota.
        if ((string) config('mail.default') === 'log') {
            $this->newLine();
            $this->warn('OJO: `MAIL_MAILER=log`. El correo NO sale del servidor.');
            $this->line('    El enlace esta escrito en storage/logs/laravel.log.');
        }

        $internos = DB::table('users')->where('user_type', 'internal')->where('status', 'active')->count();

        if ($internos >= 2) {
            $this->newLine();
            $this->info("Hay {$internos} usuarios internos activos: B-2 deja de bloquear. "
                .'Ya se pueden aprobar perfiles fiscales y verificar medios de pago.');
        }

        return self::SUCCESS;
    }

    /**
     * Comprueba el rol y explica qué hacer cuando no hay ninguno.
     *
     * Devuelve el código de salida cuando hay que abortar, o `null` si todo bien.
     */
    private function rolInvalido(string $rol): ?int
    {
        if (DB::table('roles')->where('code', $rol)->where('scope', 'internal')->exists()) {
            return null;
        }

        // La primera version decia «no existe el rol X» y debajo imprimia la
        // lista de roles internos. Cuando la tabla estaba VACIA, esa lista salia
        // vacia tambien y el mensaje se quedaba en un callejon sin salida: el
        // problema no era el rol, era que no habia ninguno. Un error tiene que
        // decir que hacer a continuacion.
        $internos = DB::table('roles')->where('scope', 'internal')->orderBy('code')->pluck('code')->all();

        if ($internos !== []) {
            $this->error("No existe el rol interno «{$rol}».");
            $this->line('Roles internos disponibles: '.implode(', ', $internos));

            return self::FAILURE;
        }

        $total = DB::table('roles')->count();

        $this->error('No hay ningun rol interno en esta base de datos.');
        $this->newLine();

        if ($total === 0) {
            $this->line('La tabla `roles` esta vacia: faltan los cimientos. Corre:');
            $this->newLine();
            $this->line('    php artisan db:seed --class=Database\\Seeders\\CimientosSeeder');
        } else {
            $this->line("Hay {$total} roles, pero ninguno con `scope = internal`:");
            foreach (DB::table('roles')->orderBy('code')->get(['code', 'scope']) as $r) {
                $this->line("    {$r->code}  ->  scope = {$r->scope}");
            }
        }

        $this->newLine();
        $this->line('Comprueba tambien que apuntas a la base que crees: '
            .'DB_DATABASE = '.(string) config('database.connections.'
                .config('database.default').'.database'));

        return self::FAILURE;
    }
}
