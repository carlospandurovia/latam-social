<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Services\Decolecta;
use App\Modules\Core\Services\TraidaDeCambio;
use Illuminate\Console\Command;

/**
 * Trae los tipos de cambio del día. Corre solo, todos los días (9.2).
 *
 * `--dias=N` recupera los N últimos días en vez de sólo hoy, y por defecto son
 * **tres**. No es capricho: si el cron no corrió el viernes ni el sábado, pedir
 * sólo el domingo deja dos huecos que nadie va a rellenar a mano. Repetir un día
 * ya traído no cuesta una fila —`uq_fx_rate` lo impide— y cuesta una petición,
 * que es un precio ridículo comparado con convertir con una tasa de la semana
 * pasada.
 *
 * Termina en 0 aunque la API falle. Un cron que devuelve error hace ruido todos
 * los días en un sitio donde nadie mira; lo que hay que mirar es
 * `fx_fetch_runs`, que es donde queda escrito qué pasó y que la pantalla enseña.
 * Sólo devuelve 1 si **ninguno** de los días pedidos se pudo traer, que sí es
 * una avería y no un día sin publicar.
 */
final class TraerTiposDeCambioCommand extends Command
{
    protected $signature = 'cambio:traer
        {--fecha= : Un dia concreto (YYYY-MM-DD). Si se pasa, se ignora --dias}
        {--dias=3 : Cuantos dias hacia atras recuperar, contando hoy}';

    protected $description = 'Trae de Decolecta el tipo de cambio de SUNAT y lo anota';

    public function handle(): int
    {
        $fechas = $this->fechas();
        $bien = 0;

        foreach ($fechas as $fecha) {
            $resultado = Decolecta::traer($fecha);
            TraidaDeCambio::anotar(Decolecta::FUENTE, $fecha, $resultado);

            $linea = "{$fecha}  {$resultado['outcome']}  {$resultado['detalle']}";

            if ($resultado['outcome'] === Decolecta::OK) {
                $bien++;
                $this->info($linea);

                continue;
            }

            // Un dia sin publicar --un feriado-- no es una averia, y no se
            // pinta como si lo fuera: quien lee esto todos los dias deja de
            // leerlo si le grita por lo normal.
            $resultado['http'] === 404 ? $this->line($linea) : $this->warn($linea);
        }

        if ($bien === 0 && $fechas !== []) {
            $this->error('Ningun dia se pudo traer. Mire la pantalla de tipos de cambio.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function fechas(): array
    {
        $una = $this->option('fecha');

        if (is_string($una) && $una !== '') {
            return [substr($una, 0, 10)];
        }

        $dias = max(1, min(31, (int) $this->option('dias')));

        // De la mas vieja a la mas nueva: si algo se corta a la mitad, lo que
        // queda anotado es lo antiguo, y el hueco queda al final --que es el
        // que la proxima corrida vuelve a intentar--.
        return array_map(
            fn (int $i): string => date('Y-m-d', strtotime("-{$i} day")),
            range($dias - 1, 0),
        );
    }
}
