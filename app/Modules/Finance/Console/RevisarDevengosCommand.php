<?php

declare(strict_types=1);

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Services\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * Y hace una cosa más desde `9.4`: **anota las aceptaciones que se quedaron sin
 * devengo**. El listener las anota al aceptar, así que eso debería salir siempre
 * en cero — y si no sale en cero, eso es exactamente la noticia. Es la misma red
 * que `8.1` dejó para los entregables.
 */
final class RevisarDevengosCommand extends Command
{
    protected $signature = 'ledger:revisar {--limite=500 : Cuantos devengos mirar como maximo}';

    protected $description = 'Pasa a pagable los devengos que cumplen BR-FIN-003';

    public function handle(): int
    {
        $limite = max(1, min(5000, (int) $this->option('limite')));

        // Primero la red de seguridad: aceptaciones sin asiento. Desde `9.4` el
        // listener las anota al aceptar, asi que esto deberia salir siempre en
        // cero --y si no sale en cero, eso ES la noticia--. Va antes de revisar
        // para que un devengo recuperado hoy pueda pasar a pagable hoy mismo.
        $rescatados = 0;

        foreach (Ledger::sinDevengo($limite) as $participacion) {
            try {
                Ledger::devengar((int) $participacion->id);
                $rescatados++;
            } catch (Throwable $e) {
                $this->warn("No se pudo devengar la participacion {$participacion->id}: {$e->getMessage()}");
            }
        }

        if ($rescatados > 0) {
            $this->warn(sprintf(
                'Habia %d aceptaciones sin devengo. Se anotaron ahora, pero el listener deberia '
                .'haberlo hecho al aceptar: mire el log.',
                $rescatados,
            ));
        }

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
            'Devengos mirados: %d. Pasados a pagable: %d. Rescatados: %d.',
            $devengos->count(), $movidos, $rescatados,
        ));

        return self::SUCCESS;
    }
}
