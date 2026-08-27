<?php

declare(strict_types=1);

namespace App\Modules\Content\Listeners;

use App\Modules\Content\Services\Entregables;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cuando un creador acepta, se crea lo que tiene que entregar (8.1).
 *
 * ### Por evento, y otra vez por el mismo motivo
 *
 * `deptrac.yaml` dice `Campaign: [Framework, Shared, Core, Identity, Creator,
 * Client]` — **Content no está en esa lista**, y sí al revés: `Content: [...,
 * Campaign]`. O sea que Campaign no puede llamar a Content, igual que no puede
 * llamar a Communication.
 *
 * Y está bien que sea así. Aceptar una invitación es un compromiso económico
 * entre dos partes; generar la lista de tareas es una consecuencia. Si lo
 * segundo falla, lo primero **sigue siendo cierto**.
 *
 * ### Qué pasa si esto revienta
 *
 * El creador queda aceptado y sin nada que entregar. Es peor que un correo que
 * no sale, así que no se traga: `report()` lo manda al manejador de errores y la
 * pantalla interna de entregables ofrece **generarlos a mano** para cualquier
 * participación aceptada que no los tenga.
 *
 * Bloquear la aceptación habría sido peor: el creador ya pulsó «acepto», el
 * importe ya está congelado, y devolverle un error por algo que no es asunto
 * suyo deja la participación en un estado que nadie entiende.
 */
final class GenerarEntregables
{
    public function handle(EventoOcurrido $evento): void
    {
        if ($evento->nombre !== 'campaign_creator.accepted') {
            return;
        }

        try {
            $creados = Entregables::generarPara($evento->idEntidad);

            if ($creados === 0) {
                // Cero no siempre es un fallo --el brief puede estar vacio, o ya
                // los tenia-- pero es lo bastante raro como para dejarlo escrito.
                Log::info('Aceptacion sin entregables generados.', [
                    'participacion' => $evento->idEntidad,
                ]);
            }
        } catch (Throwable $e) {
            report($e);

            Log::error('No se pudieron generar los entregables de una aceptacion.', [
                'participacion' => $evento->idEntidad,
                'motivo' => $e->getMessage(),
            ]);
        }
    }
}
