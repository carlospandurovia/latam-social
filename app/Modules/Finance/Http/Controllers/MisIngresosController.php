<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Ledger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lo que el creador ha ganado, y en qué punto está cada cosa (9.8).
 *
 * Es la primera vez que un creador ve su dinero en el sistema.
 *
 * ### La propiedad se comprueba aquí, como en `8.1`
 *
 * `creator.portal` dice *«esta persona puede ver un portal de creador»*. **No
 * dice cuál.** Lo que ata la pantalla a sus datos es `creators.user_id =
 * Auth::id()`, y se comprueba en la acción: sin eso, cualquier creador con el
 * permiso vería el dinero de otro.
 *
 * `BR-SEC-006`: si no hay creador para este usuario se devuelve **404 y no
 * 403** — no se revela que el recurso exista.
 *
 * ### Es de SÓLO LECTURA, y eso no es una limitación
 *
 * No hay ningún botón. El creador no mueve dinero: lo mira. Cada estado de su
 * lista lo puso el sistema o una persona del equipo, y darle aquí una acción
 * sería darle una palanca sobre un libro mayor que no se edita (`BR-FIN-002`).
 */
final class MisIngresosController
{
    public function __invoke(): View
    {
        $creadorId = self::creadorDe();

        // Todo lo que cruza a la vista sale de `misIngresos()`, que enumera
        // columnas. Aqui NO se anade nada leido a mano: si algun dia hace falta
        // un dato mas, se anade alli --que es donde esta escrito por que cada
        // uno puede cruzar-- y no aqui, que es donde se olvida.
        $ingresos = Ledger::misIngresos($creadorId);

        return view('ingresos.mios', [
            'saldo' => $ingresos['saldo'],
            'asientos' => $ingresos['asientos'],
        ]);
    }

    private static function creadorDe(): int
    {
        $id = DB::table('creators')->where('user_id', Auth::id())->value('id');

        if ($id === null) {
            throw new NotFoundHttpException('No hay ningun creador para este usuario.');
        }

        return (int) $id;
    }
}
