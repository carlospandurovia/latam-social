<?php

declare(strict_types=1);

namespace App\Modules\Content\Console;

use App\Modules\Content\Services\Permanencia;
use Illuminate\Console\Command;

/**
 * Vigila la permanencia mínima de los posts (8.8).
 *
 * ### Hace dos cosas, y NINGUNA de ellas es salir a Internet
 *
 * 1. **Cierra las ventanas cumplidas.** Una publicación vigilada cuyo
 *    `permanence_until` ya pasó pasa a `fulfilled`. Es lo único que el sistema
 *    puede decidir solo, porque no depende de mirar ningún post: compara una
 *    fecha con el calendario. Y es lo que habilita el pago.
 *
 * 2. **Cuenta las desatendidas.** Las que llevan una semana con la ventana
 *    abierta y sin que nadie las haya comprobado. No cambia nada: lo dice, para
 *    que la cifra aparezca en `storage/logs/planificador.log` y alguien mire la
 *    bandeja.
 *
 * Lo que **no** hace es comprobar si el post sigue vivo, y no es un descuido.
 * Instagram y TikTok devuelven lo mismo ante un post borrado, un perfil puesto
 * en privado y un bloqueo geográfico. Una comprobación automática daría una
 * cifra que *parece* medir algo, y de esa cifra colgaría un pago retenido
 * (`DEC-146`). La comprobación la anota una persona desde la bandeja, o un
 * proceso externo que sepa mirar de verdad —las APIs oficiales, que son `F12`—.
 *
 * ### En producción esto es una línea de cron
 *
 * `docs/18` §2: en hosting compartido no hay demonio. Si la línea del
 * planificador no está puesta, este comando no corre, **las ventanas cumplidas
 * no se cierran y ningún pago se habilita** — y nada avisa. Por eso el runbook la
 * lista y por eso el comando dice siempre lo que hizo, también cuando son cero.
 *
 *   php artisan permanencia:vigilar
 */
final class VigilarPermanenciaCommand extends Command
{
    protected $signature = 'permanencia:vigilar';

    protected $description = 'Cierra las ventanas de permanencia cumplidas y cuenta las desatendidas (8.8).';

    public function handle(): int
    {
        $cerradas = Permanencia::cerrarVentanas();
        $desatendidas = Permanencia::desatendidas()->count();

        // Se dice siempre, tambien cuando son cero: «0 ventanas cerradas» en el
        // log demuestra que el comando corrio, y el silencio no distingue entre
        // «no habia ninguna» y «el cron no esta puesto». Es la leccion de 7.6.
        $this->info("{$cerradas} ventana(s) de permanencia cumplida(s) y cerrada(s).");

        if ($desatendidas > 0) {
            $this->warn("{$desatendidas} publicacion(es) vigilada(s) sin comprobar desde hace "
                .Permanencia::DIAS_DESATENDIDA.' dias o mas.');
        } else {
            $this->info('0 publicaciones vigiladas sin comprobar.');
        }

        return self::SUCCESS;
    }
}
