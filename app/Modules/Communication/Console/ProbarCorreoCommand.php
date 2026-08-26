<?php

declare(strict_types=1);

namespace App\Modules\Communication\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Comprueba que el correo saliente funciona, sin tocar `email_log` (4.9).
 *
 * Existe para separar dos preguntas que se confunden siempre: *«¿está mal la
 * plantilla?»* y *«¿está mal el SMTP?»*. Esto contesta la segunda, y por eso no
 * usa plantilla ni escribe registro: si falla, el problema es la configuración.
 */
final class ProbarCorreoCommand extends Command
{
    protected $signature = 'correos:probar {destinatario : A donde mandar la prueba}';

    protected $description = 'Manda un correo de prueba para comprobar la configuracion de salida.';

    public function handle(): int
    {
        $a = (string) $this->argument('destinatario');
        $comoSale = (string) config('mail.default');

        $this->line("Enviando por «{$comoSale}» a {$a}...");

        try {
            Mail::raw(
                "Prueba de correo de LATAM Social.\n\nSi lee esto, la salida de correo funciona.",
                fn ($m) => $m->to($a)->subject('Prueba de correo - LATAM Social'),
            );
        } catch (Throwable $e) {
            $this->error('No salio: '.$e->getMessage());
            $this->line('');
            $this->line('  Revise MAIL_HOST, MAIL_PORT, MAIL_USERNAME y MAIL_PASSWORD en el .env.');

            return self::FAILURE;
        }

        $this->info('Enviado sin errores.');

        if ($comoSale === 'log') {
            $this->line('');
            $this->warn('OJO: `MAIL_MAILER=log`. El correo NO ha salido a internet: se escribio');
            $this->warn('en storage/logs/laravel.log. Para enviar de verdad, ponga `smtp` y las');
            $this->warn('credenciales en el .env.');
        }

        return self::SUCCESS;
    }
}
