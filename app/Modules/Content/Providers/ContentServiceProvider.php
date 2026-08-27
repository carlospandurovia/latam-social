<?php

declare(strict_types=1);

namespace App\Modules\Content\Providers;

use App\Modules\Content\Console\VigilarPermanenciaCommand;
use App\Modules\Content\Listeners\GenerarEntregables;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Content aporta al framework (8.1, 8.8).
 *
 * El oyente se registra aquí y no en Campaign, que es lo que mantiene el grafo
 * acíclico: Campaign levanta un nombre y un array y **no sabe que alguien
 * escucha**; Content escucha y **no sabe quién lo levantó**.
 *
 * ### El planificador de permanencia se declara aquí (8.8)
 *
 * Por lo mismo que el de invitaciones se declara en Campaign: quien sabe cada
 * cuánto hay que mirar una ventana de permanencia es el módulo que la abre.
 *
 * **Una vez al día, temprano.** `permanence_until` es una `DATE` y una ventana de
 * permanencia se contrata en días: comprobarla cada diez minutos sería trabajo
 * repetido 143 veces para un dato que cambia como mucho una vez al día. A las
 * 6:00 porque lo que cierra —una ventana cumplida— habilita un pago, y conviene
 * que esté hecho antes de que nadie abra la pantalla de finanzas.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VigilarPermanenciaCommand::class]);
        }
    }

    public function boot(): void
    {
        Event::listen(EventoOcurrido::class, GenerarEntregables::class);

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $planificador */
            $planificador = $this->app->make(Schedule::class);

            $planificador->command('permanencia:vigilar')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                // Al log del planificador y no al de la aplicacion: «.corrio el
                // cron?» tiene que poder contestarse mirando un sitio (7.6).
                ->appendOutputTo(storage_path('logs/planificador.log'));
        });
    }
}
