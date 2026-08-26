<?php

declare(strict_types=1);

namespace App\Modules\Communication\Providers;

use App\Modules\Communication\Console\ProbarCorreoCommand;
use App\Modules\Communication\Console\PublicarPlantillaCommand;
use Illuminate\Support\ServiceProvider;

/** Lo que el módulo Communication aporta al framework (4.9). */
final class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublicarPlantillaCommand::class,
                ProbarCorreoCommand::class,
            ]);
        }
    }
}
