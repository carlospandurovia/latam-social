<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Console\CambiarContrasenaCommand;
use App\Modules\Identity\Console\CrearUsuarioInternoCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Identity aporta al framework.
 *
 * Mismo motivo que `CoreServiceProvider`: `ModuleServiceProvider` vive en
 * `App\Shared`, y en `deptrac.yaml` la capa `Shared` no puede depender de
 * ningún módulo. Cada módulo que necesite registrar comandos tiene el suyo.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CambiarContrasenaCommand::class,
                CrearUsuarioInternoCommand::class,
            ]);
        }
    }
}
