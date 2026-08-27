<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Console;

use App\Modules\Campaign\Services\Invitaciones;
use Illuminate\Console\Command;

/**
 * Cierra las invitaciones cuyo plazo pasó (7.6).
 *
 * ### Por qué hace falta un comando si la caducidad ya se deduce
 *
 * `expires_at` comparado con el reloj ya impide que el creador conteste: eso no
 * necesita que nadie escriba nada. Pero la **participación** seguiría en
 * `invited` para siempre, y eso tiene tres consecuencias que sí se notan:
 *
 * | Qué queda mal | Por qué importa |
 * |---|---|
 * | Su importe sigue contando contra el presupuesto | `BR-CAMPAIGN-005` bloquea a otros por dinero comprometido con alguien que nunca contestó |
 * | El cupo del mercado no se libera | `target_creators` cuenta plazas ocupadas por nadie |
 * | La lista dice «invitado» | el operador cree que hay una conversación abierta |
 *
 * ### Se ejecuta desde el planificador, y en producción eso es un cron
 *
 * `docs/18` §2 explica que en hosting compartido no hay demonio: hay dos líneas
 * de cron, la de la cola y la del planificador. Ésta cuelga de la segunda. Si esa
 * línea no está puesta, este comando no corre y **nada avisa**: por eso el
 * runbook la lista y por eso conviene mirar de vez en cuando cuántas cierra.
 *
 *   php artisan invitaciones:caducar
 */
final class CaducarInvitacionesCommand extends Command
{
    protected $signature = 'invitaciones:caducar';

    protected $description = 'Pasa a caducadas las invitaciones cuyo plazo vencio (7.6).';

    public function handle(): int
    {
        $cerradas = Invitaciones::caducar();

        // Se dice siempre, tambien cuando son cero. «0 invitaciones caducadas»
        // en el log demuestra que el comando corrio; el silencio no distingue
        // entre «no habia ninguna» y «el cron no esta puesto».
        $this->info("{$cerradas} invitacion(es) caducada(s).");

        return self::SUCCESS;
    }
}
