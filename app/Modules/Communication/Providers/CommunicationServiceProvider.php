<?php

declare(strict_types=1);

namespace App\Modules\Communication\Providers;

use App\Modules\Communication\Console\ProbarCorreoCommand;
use App\Modules\Communication\Console\PublicarPlantillaCommand;
use App\Modules\Communication\Listeners\AvisarCambioSensible;
use App\Modules\Communication\Listeners\EnviarEnlaceDeContrasena;
use App\Modules\Identity\Eventos\EnlaceDeContrasenaEmitido;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Communication aporta al framework (4.9, 4.13).
 *
 * ### El oyente se registra AQUÍ, y eso es lo que mantiene el grafo acíclico
 *
 * Creator levanta el evento y **no sabe que alguien escucha**. Communication
 * escucha y **no sabe quién lo levantó**: le llega un nombre y un array. Así
 * `deptrac.yaml` sigue en verde —`Communication: [Framework, Shared, Core,
 * Identity]`, sin Creator— y un fallo del correo no puede tumbar la captura de
 * un dato fiscal.
 */
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

    public function boot(): void
    {
        Event::listen(EventoOcurrido::class, AvisarCambioSensible::class);

        // `5.9` y `4.1`: este SI es un evento de Identity con tipo propio, y no
        // un `EventoOcurrido` generico. La razon esta en el propio evento: su
        // payload lleva el token en claro y `EventoOcurrido` se PERSISTE.
        Event::listen(EnlaceDeContrasenaEmitido::class, EnviarEnlaceDeContrasena::class);
    }
}
