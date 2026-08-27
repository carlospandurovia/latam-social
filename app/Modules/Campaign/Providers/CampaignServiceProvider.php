<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Providers;

use App\Modules\Campaign\Console\CaducarInvitacionesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Campaign aporta al framework (7.6).
 *
 * ### El planificador se declara AQUÍ y no en `routes/console.php`
 *
 * Porque quien sabe cada cuánto hay que cerrar invitaciones es el módulo que las
 * abre. En `routes/console.php` sería una línea suelta lejos de la regla que la
 * explica, y el día que el plazo mínimo baje de una hora nadie se acordaría de
 * venir a mirarla.
 *
 * **Cada diez minutos.** El plazo mínimo que admite `ck_camp_invitation_hours`
 * es una hora, así que diez minutos deja el retraso máximo en un 17 % del plazo
 * más corto posible. Cada hora sería aceptable para 72 h y ridículo para 1 h.
 *
 * `withoutOverlapping()` porque si una pasada se alarga —cientos de invitaciones
 * vencidas de golpe tras un fin de semana— dos pasadas simultáneas se pelearían
 * por las mismas filas.
 */
final class CampaignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CaducarInvitacionesCommand::class]);
        }
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $planificador */
            $planificador = $this->app->make(Schedule::class);

            $planificador->command('invitaciones:caducar')
                ->everyTenMinutes()
                ->withoutOverlapping()
                // A un archivo propio y no al de la aplicacion: «.corrio el
                // cron?» tiene que poder contestarse mirando un sitio, y en el
                // log general se pierde entre todo lo demas. `docs/18` §5 ya
                // manda mirar `queue.log` por lo mismo.
                ->appendOutputTo(storage_path('logs/planificador.log'));
        });
    }
}
