<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Services\Ledger;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Cuando un creador acepta, el dinero queda anotado (9.4).
 *
 * ### Por qué al aceptar y no al terminar
 *
 * Porque **al aceptar es cuando existe la deuda**. `7.5` congela el
 * `agreed_amount` en ese instante y `BR-CREATOR-008` impide cambiarlo después
 * sin una enmienda firmada por las dos partes: a partir de ahí se le debe ese
 * importe, hayan pasado o no las cosas que hacen falta para pagárselo.
 *
 * Que todavía no se le pueda pagar es **otra cosa**, y vive en el estado:
 * `accrued` significa exactamente «se le debe y aún no se le puede pagar».
 * Devengar al terminar dejaría el trabajo hecho y el compromiso invisible hasta
 * el final, que es como una campaña se cierra sin que nadie sepa cuánto costó.
 *
 * ### Por evento, y por el mismo motivo que `8.1`
 *
 * `deptrac.yaml` dice `Campaign: [Framework, Shared, Core, Identity, Creator,
 * Client]` — **Finance no está en esa lista**, y sí al revés. Campaign no puede
 * llamar a Finance.
 *
 * Y está bien que sea así: aceptar es un compromiso entre dos partes; anotarlo
 * en el libro mayor es una consecuencia. Si lo segundo falla, lo primero sigue
 * siendo cierto — y el creador ya pulsó «acepto», así que devolverle un error
 * por algo que no es asunto suyo deja la participación en un estado que nadie
 * entiende.
 *
 * ### Los dos «no» que NO son fallos
 *
 * 1. **Ya había un devengo.** `uq_ledger_devengo` lo rechaza, y eso es la regla
 *    funcionando: `9.3` la puso justo para esto. Se anota y se sigue.
 * 2. **La colaboración es gratuita.** Un canje no devenga nada, y un asiento de
 *    cero no es un asiento (`ck_ledger_amount`). Es normal, no una avería.
 *
 * Distinguirlos importa: si los tres finales —los dos de arriba y un error de
 * verdad— se escribieran igual, el log diría que algo falló cada vez que alguien
 * acepta un canje, y a la tercera nadie lo lee.
 */
final class DevengarParticipacion
{
    public function handle(EventoOcurrido $evento): void
    {
        if ($evento->nombre !== 'campaign_creator.accepted') {
            return;
        }

        $participacionId = (int) $evento->idEntidad;

        try {
            $uuid = Ledger::devengar($participacionId);

            Log::info('Devengo anotado al aceptar.', [
                'participacion' => $participacionId,
                'asiento' => $uuid,
            ]);
        } catch (QueryException $e) {
            // `uq_ledger_devengo`: ya lo tenia. Es la regla de `9.3` haciendo su
            // trabajo, no un fallo. Cualquier otro error de base SI lo es.
            if (!str_contains($e->getMessage(), 'uq_ledger_devengo')) {
                $this->avisar($participacionId, $e);

                return;
            }

            Log::info('La participacion ya tenia devengo: no se anota otro.', [
                'participacion' => $participacionId,
            ]);
        } catch (RuntimeException $e) {
            // Colaboracion gratuita, o una aceptacion que no lo era. `devengar()`
            // ya lo dice con palabras; aqui solo se decide que no es una averia.
            Log::info('No habia nada que devengar.', [
                'participacion' => $participacionId,
                'motivo' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->avisar($participacionId, $e);
        }
    }

    private function avisar(int $participacionId, Throwable $e): void
    {
        report($e);

        Log::error('No se pudo devengar una aceptacion.', [
            'participacion' => $participacionId,
            'motivo' => $e->getMessage(),
        ]);
    }
}
