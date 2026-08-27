<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Services\EnlacesDeContrasena;
use App\Shared\Audit\Bitacora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Reponer la contraseña de un usuario interno.
 *
 * ### Por qué es un comando y no un `UPDATE`
 *
 * Porque hay cuatro cosas que hacer bien y ninguna es obvia:
 *
 * 1. **Cifrarla.** Un `UPDATE ... SET password='...'` deja la contraseña en
 *    claro en la base y en el historial del terminal.
 * 2. **Marcar `must_change_password`.** Quien repone la contraseña la conoce.
 *    Mientras no se cambie, ese usuario y el que la repuso **no son dos personas
 *    distintas** para el sistema, y de eso dependen las reglas que exigen dos
 *    personas para aprobar un perfil fiscal o verificar un medio de pago
 *    (`ck_ctp_segregation`, `ck_cpm_segregation`). Es `T-23` visto desde el otro
 *    lado.
 * 3. **Anotarlo.** Reponer una contraseña ajena es exactamente lo que una
 *    bitácora existe para registrar. Se anota **que** pasó, nunca a qué:
 *    `Bitacora::REDACTAR` oculta cualquier campo con `password` en el nombre.
 * 4. **No teclearla en la línea de órdenes.** Lo que se escribe ahí queda en el
 *    historial del shell. Por eso se pide oculta, o se genera.
 *
 * ### Desde `T-36`, la forma normal es `--enlace`
 *
 * `BR-SEC-004` dice *«nunca se transmite una contraseña en texto claro por
 * ningún canal»*, y desde `5.9` hay una pieza que lo cumple: un enlace de un solo
 * uso con caducidad, del que sólo se guarda la huella. Con `--enlace` este
 * comando manda uno y **no genera ninguna contraseña**: nadie la teclea, nadie la
 * dicta y nadie la ve.
 *
 * Las otras dos formas siguen existiendo **a propósito**, y son el cristal que se
 * rompe en caso de emergencia: si el correo no sale —`Q-20` sigue abierta— un
 * enlace no llega a ningún sitio y el administrador se queda fuera de su propio
 * sistema. El comando avisa cada vez de que ése es el camino excepcional.
 *
 * ### Uso
 *
 *     php artisan usuarios:contrasena ana@cts.pe --enlace     <- lo normal
 *     php artisan usuarios:contrasena admin@portalcts.com --generar
 *     php artisan usuarios:contrasena admin@portalcts.com
 */
final class CambiarContrasenaCommand extends Command
{
    protected $signature = 'usuarios:contrasena
        {email : El correo del usuario}
        {--enlace : Manda un enlace para que la elija su dueno (lo normal desde T-36)}
        {--generar : La genera el sistema en vez de pedirla}
        {--sin-forzar-cambio : NO exigir el cambio en el primer acceso (desaconsejado)}';

    protected $description = 'Repone la contrasena de un usuario interno y exige que la cambie al entrar.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        // Constructor de consultas y no `App\Models\User`: `app/Models` no
        // pertenece a ninguna capa de Deptrac, asi que un modulo que importe el
        // modelo es una dependencia sin cubrir y rompe la puerta de fronteras.
        // Es la misma razon que `Permisos` documenta desde 1.x, y este comando
        // la aprendio por las malas al ponerse Deptrac en rojo.
        $usuario = DB::table('users')->where('email', $email)->first(['id', 'email']);

        if ($usuario === null) {
            $this->error("No hay ningun usuario con el correo {$email}.");
            $this->line('');
            $this->line('  Los que hay, con su rol:');
            foreach (DB::table('users')->orderBy('email')->limit(20)->get(['id', 'email']) as $otro) {
                $roles = self::rolesDe((int) $otro->id);
                $this->line(sprintf('    %-34s %s', $otro->email, $roles === '' ? '(SIN ROL)' : $roles));
            }
            $this->line('');
            $this->line('  Si hace falta crearlo:  php artisan usuarios:crear');

            return self::FAILURE;
        }

        // `T-36`: el camino normal. No se genera ninguna contrasena, asi que no
        // hay nada que dictar ni que se quede en el historial de nadie.
        if ($this->option('enlace')) {
            return $this->porEnlace($usuario);
        }

        $this->line('');
        $this->warn('Reponiendo la contrasena a mano. Lo normal es `--enlace`: asi la elige');
        $this->warn('su dueno y nadie mas la ve (BR-SEC-004). Esto es el camino de');
        $this->warn('emergencia, para cuando el correo no sale.');
        $this->line('');

        if ($this->option('generar')) {
            // 16 de `Str::password()`. El liston no lo pone este comando: lo
            // pone la pantalla de cambio, que exige 12.
            $clave = Str::password(16);
        } else {
            $clave = (string) $this->secret('Contrasena nueva (no se ve al teclear)');

            if (mb_strlen($clave) < 12) {
                // El mismo minimo que exige la pantalla de cambio (`T-23`). Que
                // el camino de consola sea mas laxo que el de pantalla seria
                // dejar la puerta de atras abierta.
                $this->error('Al menos 12 caracteres. La pantalla de cambio exige eso mismo.');

                return self::FAILURE;
            }

            if ($clave !== (string) $this->secret('Repitala')) {
                $this->error('Las dos no coinciden. No se ha tocado nada.');

                return self::FAILURE;
            }
        }

        $forzar = !$this->option('sin-forzar-cambio');

        DB::table('users')->where('id', $usuario->id)->update([
            'password' => Hash::make($clave),
            'must_change_password' => $forzar,
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'user.password_reset',
            tipoEntidad: 'user',
            idEntidad: (int) $usuario->id,
            // Solo el hecho. `REDACTAR` ocultaria el valor de todas formas, pero
            // no se manda ni para que lo oculte.
            cambios: ['password' => ['antes' => null, 'despues' => null]],
        );

        $roles = self::rolesDe((int) $usuario->id);

        $this->line('');
        $this->info("Contrasena repuesta para {$usuario->email}.");
        $this->line('  Rol: '.($roles === '' ? 'NINGUNO' : $roles));

        // Un usuario sin rol entra y no ve nada: todas las pantallas de negocio
        // exigen un permiso, y los permisos cuelgan del rol. Sin esto, el
        // sintoma seria «entro pero me da 403 en todo» y parece un fallo del
        // sistema en vez de una cuenta a medio configurar.
        if ($roles === '') {
            $this->line('');
            $this->warn('Este usuario no tiene ningun rol: va a entrar y no va a ver ninguna');
            $this->warn('pantalla de negocio. Se le asigna uno con `usuarios:crear`, o');
            $this->warn('directamente en `role_user`. Los roles son: admin, campaign_manager,');
            $this->warn('finance, content_reviewer.');
        }

        if ($this->option('generar')) {
            $this->line('');
            $this->line('    '.$clave);
            $this->line('');
            $this->warn('Se enseña UNA vez. No queda guardada en ningun sitio en claro.');
        }

        if ($forzar) {
            $this->line('');
            $this->line('  Al entrar ira directo a la pantalla de cambio y no podra ir a ningun');
            $this->line('  otro sitio hasta cambiarla. Es `T-23`: mientras siga siendo esta,');
            $this->line('  usted y ese usuario no son dos personas distintas para el sistema.');
        }

        return self::SUCCESS;
    }

    /** Los roles de un usuario, separados por coma. Cadena vacia si no tiene. */
    /**
     * Manda un enlace en vez de reponer nada. `T-36`.
     *
     * No toca `password`: la que hubiera sigue valiendo hasta que el enlace se
     * use. Es deliberado — si el enlace no llega, la persona no se queda peor de
     * como estaba. Al usarlo, `EnlacesDeContrasena::consumir()` cierra además
     * todas sus sesiones abiertas.
     */
    private function porEnlace(object $usuario): int
    {
        EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->line('');
        $this->info("Enlace enviado a {$usuario->email}.");
        $this->line('  Vale '.EnlacesDeContrasena::HORAS['reset'].' hora(s) y solo se puede usar una vez.');
        $this->line('  Su contrasena actual sigue valiendo hasta que lo use: si el correo no');
        $this->line('  llega, no se queda peor de como estaba.');

        if ((string) config('mail.default') === 'log') {
            $this->line('');
            $this->warn('OJO: `MAIL_MAILER=log`. El correo NO sale del servidor.');
            $this->line('    El enlace esta escrito en storage/logs/laravel.log.');
        }

        return self::SUCCESS;
    }

    private static function rolesDe(int $usuarioId): string
    {
        /** @var list<string> $codigos */
        $codigos = DB::table('role_user as ru')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('ru.user_id', $usuarioId)
            ->orderBy('r.code')
            ->pluck('r.code')->all();

        return implode(', ', $codigos);
    }
}
