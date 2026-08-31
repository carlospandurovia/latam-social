<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\PublicarTerminosCommand;
use App\Modules\Core\Console\TraerTiposDeCambioCommand;
use App\Modules\Core\Services\Marca;
use App\Shared\Files\Vigilante;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\View;
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
        // 9.17: la marca en las plantillas. Un compositor y no `View::share()`:
        // `share` se evalua en CADA peticion, incluidas las que devuelven un
        // archivo o un redirect y no pintan nada, y eso es una consulta por
        // peticion para nadie. El compositor solo corre si la plantilla se usa.
        //
        // Las tres son las que llevaban «LATAM Social» y el favicon escritos.
        //
        // `errors::403` **con dos puntos dobles** y no solo `errors.403`: el
        // manejador de excepciones de Laravel resuelve la pantalla de error por
        // el espacio de nombres `errors::`, no por la ruta de la vista. Con solo
        // `errors.403` registrado, el compositor no corria, `$marca` no existia
        // y la pantalla de «sin permiso» respondia **500 en vez de 403**. Lo
        // encontro `MarcaPlataformaTest`, que comprobaba un 403 y recibio un 500.
        View::composer(['layouts.panel', 'layouts.acceso', 'errors.403', 'errors::403'],
            static function (\Illuminate\View\View $vista): void {
                $vista->with('marca', Marca::datos());
            });

        // 9.17: el logotipo y el favicon de la plataforma.
        //
        // Su puerta de verdad es publica --sale en la pantalla de acceso, que se
        // ve sin sesion-- y esta regla existe para el OTRO camino: `9.15` niega
        // por omision, asi que sin ella un archivo de marca abierto por
        // `/archivos/{uuid}` daria 403 y nadie sabria por que. Lo ve cualquiera
        // que haya entrado, porque ya lo ha visto en la barra lateral.
        //
        // No es sensible: anotar en la bitacora cada vez que alguien carga el
        // logotipo es anotar cada carga de cada pantalla.
        Vigilante::regla(Marca::PROPOSITO, static fn (object $archivo, int $usuarioId): bool => $usuarioId > 0);

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
