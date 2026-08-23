<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\PublicarTerminosCommand;
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
            ]);
        }
    }
}
