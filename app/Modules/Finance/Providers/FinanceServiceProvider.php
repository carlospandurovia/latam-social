<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Console\RevisarDevengosCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Finance aporta al framework (9.3).
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RevisarDevengosCommand::class]);
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

            // A las 06:30: DESPUES de `permanencia:vigilar` (06:00), que es lo
            // que cierra las ventanas de permanencia cumplidas --y de eso
            // depende que una publicacion cuente como verificada--. Al reves, el
            // barrido miraria el estado de ayer y todo cobraria un dia tarde.
            $planificador->command('ledger:revisar')
                ->dailyAt('06:30')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/planificador.log'));
        });
    }
}
