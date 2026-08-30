<?php

declare(strict_types=1);

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Services\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mueve a `payable` los devengos que ya cumplen las cinco (9.3).
 *
 * Corre solo cada día. **Es un barrido y no un disparador de eventos** a
 * propósito: las cinco condiciones de `BR-FIN-003` se cumplen en cinco sitios
 * distintos —al verificar una publicación, al aprobar un perfil fiscal, al
 * verificar una cuenta bancaria— y la quinta puede llegar meses después de la
 * primera. Un listener por evento tendría que existir cinco veces y acordarse de
 * mirar las otras cuatro; un barrido diario contesta la misma pregunta una vez.
 *
 * `9.4` añadirá el devengo por eventos —que es otra cosa: **crear** el asiento
 * cuando la campaña arranca—. Esto seguirá corriendo igual, porque lo que
 * comprueba no depende de que ningún evento se haya emitido.
 *
 * Es idempotente: pasar dos veces sobre un asiento ya `payable` no hace nada.
 */
final class RevisarDevengosCommand extends Command
{
    protected $signature = 'ledger:revisar {--limite=500 : Cuantos devengos mirar como maximo}';

    protected $description = 'Pasa a pagable los devengos que cumplen BR-FIN-003';

    public function handle(): int
    {
        $limite = max(1, min(5000, (int) $this->option('limite')));

        $devengos = DB::table('ledger_entries')
            ->where('entry_type', Ledger::DEVENGO)
            ->where('status', Ledger::DEVENGADO)
            ->orderBy('occurred_at')
            ->limit($limite)
            ->pluck('id');

        $movidos = 0;

        foreach ($devengos as $id) {
            if (Ledger::revisarPagable((int) $id)) {
                $movidos++;
            }
        }

        // Se dice SIEMPRE cuantos se miraron, aunque no se mueva ninguno: un
        // comando que no imprime nada cuando no hace nada es indistinguible de
        // un comando que no corrio.
        $this->info(sprintf(
            'Devengos mirados: %d. Pasados a pagable: %d.',
            $devengos->count(), $movidos,
        ));

        return self::SUCCESS;
    }
}
