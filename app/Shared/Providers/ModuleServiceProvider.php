<?php

declare(strict_types=1);

namespace App\Shared\Providers;

use App\Shared\Console\VerificarEsquemaCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registra lo que cada módulo aporta al framework.
 *
 * Las migraciones viven dentro de su módulo (app/Modules/<M>/Database/Migrations)
 * y no en database/migrations, porque el dueño de una tabla es el módulo, no el
 * proyecto. Laravel las ordena por el timestamp del nombre, así que el orden
 * entre módulos sigue siendo determinista.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom($this->rutasDeMigraciones());
    }

    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerificarEsquemaCommand::class,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function rutasDeMigraciones(): array
    {
        $patron = app_path('Modules/*/Database/Migrations');
        $rutas = glob($patron, GLOB_ONLYDIR);

        return $rutas === false ? [] : array_values($rutas);
    }
}
