<?php

declare(strict_types=1);

namespace App\Modules\Communication\Providers;

use App\Modules\Communication\Console\ProbarCorreoCommand;
use App\Modules\Communication\Console\PublicarPlantillaCommand;
use App\Modules\Communication\Listeners\AvisarCambioSensible;
use App\Modules\Communication\Listeners\EnviarCorreoPedido;
use App\Shared\Eventos\CorreoPedido;
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

        // `5.9`, `4.1` y `7.6`: los correos cuyo contenido NO se puede guardar
        // --llevan un token en claro dentro-- no pasan por `EventoOcurrido`,
        // que persiste su payload. Van por `CorreoPedido`, que vive en `Shared`
        // para que lo pueda levantar cualquier modulo: en `5.9` vivio una
        // iteracion dentro de Identity y funciono de milagro --Identity esta en
        // la lista de arriba--; Campaign, que lo necesita igual en `7.6`, no.
        Event::listen(CorreoPedido::class, EnviarCorreoPedido::class);
    }
}
