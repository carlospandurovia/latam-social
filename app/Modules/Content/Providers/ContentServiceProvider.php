<?php

declare(strict_types=1);

namespace App\Modules\Content\Providers;

use App\Modules\Content\Listeners\GenerarEntregables;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Content aporta al framework (8.1).
 *
 * El oyente se registra aquí y no en Campaign, que es lo que mantiene el grafo
 * acíclico: Campaign levanta un nombre y un array y **no sabe que alguien
 * escucha**; Content escucha y **no sabe quién lo levantó**.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventoOcurrido::class, GenerarEntregables::class);
    }
}
