<?php

declare(strict_types=1);

namespace App\Modules\Creator\Providers;

use App\Modules\Creator\Console\RecalcularHuellasCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Creator aporta al framework.
 *
 * Mismo motivo que `IdentityServiceProvider`: `ModuleServiceProvider` vive en
 * `App\Shared`, y en `deptrac.yaml` la capa `Shared` no puede depender de
 * ningún módulo. Cada módulo que necesite registrar comandos tiene el suyo.
 */
final class CreatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RecalcularHuellasCommand::class,
            ]);
        }
    }
}
