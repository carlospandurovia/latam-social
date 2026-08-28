<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\PublicarTerminosCommand;
use App\Modules\Core\Console\TraerTiposDeCambioCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Core aporta al framework.
 *
 * Existe por una razón de arquitectura y no de comodidad: `ModuleServiceProvider`
 * vive en `App\Shared`, y en `deptrac.yaml` la capa `Shared` no puede depender
 * de ningún módulo. Registrar ahí `PublicarTerminosCommand` habría hecho que
 * Shared importara Core y CI lo habría rechazado, con razón: si Shared conoce a
 * los módulos, deja de ser compartido y pasa a ser el centro de un grafo con
 * ciclos.
 *
 * Cada módulo que necesite registrar comandos, eventos o vistas tendrá el suyo.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublicarTerminosCommand::class,
                TraerTiposDeCambioCommand::class,
            ]);
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

            // A las 05:30, antes que la vigilancia de permanencia de las 06:00:
            // si algun dia algo del ciclo de la manana necesita convertir, las
            // tasas del dia ya estan. SUNAT publica de madrugada.
            //
            // `withoutOverlapping` porque una corrida lenta --la API tarda-- no
            // debe solaparse con la siguiente: dos procesos pidiendo los mismos
            // tres dias es gastar el doble de cuota para no traer nada nuevo.
            $planificador->command('cambio:traer')
                ->dailyAt('05:30')
                ->withoutOverlapping()
                // Al log del planificador, como el resto: «.corrio el cron?»
                // tiene que poder contestarse mirando un sitio. Y ademas queda
                // en `fx_fetch_runs`, que es lo que la pantalla ensena.
                ->appendOutputTo(storage_path('logs/planificador.log'));
        });
    }
}
