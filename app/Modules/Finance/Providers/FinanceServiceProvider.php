<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Console\RevisarDevengosCommand;
use App\Modules\Finance\Emision\Armadores;
use App\Modules\Finance\Emision\Peru\ArmadorGreenter;
use App\Modules\Finance\Listeners\DevengarParticipacion;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\EventoOcurrido;
use App\Shared\Files\Vigilante;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
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
        // 9.4: al aceptar, el dinero queda anotado. Fuera del `if` de consola a
        // proposito --la aceptacion ocurre por HTTP, no por comando--, que es
        // donde `ContentServiceProvider` registra el suyo desde `8.1`.
        Event::listen(EventoOcurrido::class, DevengarParticipacion::class);

        // 9.9d: quien sabe armar un comprobante electronico en cada pais.
        //
        // Este es el UNICO sitio de Finance que nombra al adaptador peruano, y
        // por eso `deptrac` deja pasar esa arista. Lo que NO deja pasar es que
        // alguien nombre a Greenter fuera de `Emision/Peru/`: esa es la
        // frontera de `DEC-252`, y es una puerta, no un acuerdo.
        //
        // Se registra una FABRICA: armar el adaptador monta Twig y un firmador,
        // y la inmensa mayoria de las peticiones no emiten nada.
        Armadores::registrar('PE', static fn (): ArmadorGreenter => new ArmadorGreenter);

        // 9.15: quien puede mirar los archivos de finanzas. La regla vive AQUI
        // y no en un `switch` central porque necesita saber de `payouts` y de
        // `campaign_costs`, y ponerlas en Shared seria romper la frontera por
        // una consulta que `deptrac` no ve --tablas, no clases importadas--.
        //
        // El comprobante de pago es SENSIBLE: lleva la referencia del banco y a
        // veces el extracto entero, asi que abrirlo deja rastro.
        Vigilante::regla(
            'payout_proof',
            static fn (object $archivo, int $usuarioId): bool => Permisos::tiene($usuarioId, 'finance.view'),
            sensible: true,
        );

        // El comprobante de un gasto no lleva datos de nadie: es una factura
        // nuestra. Lo ve quien puede cargar gastos y quien ve finanzas.
        Vigilante::regla(
            'campaign_cost',
            static fn (object $archivo, int $usuarioId): bool => Permisos::tiene($usuarioId, 'finance.cost.manage')
                || Permisos::tiene($usuarioId, 'finance.view'),
        );

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
