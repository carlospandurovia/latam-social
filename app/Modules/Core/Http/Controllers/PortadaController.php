<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Landing;
use App\Modules\Core\Services\Marca;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Las dos portadas públicas (9.21b).
 *
 * ### A quién le habla cada una
 *
 * `/` es de las **marcas** —el lado que paga— y `/creadores` es la puerta de los
 * creadores, que es el enlace que se comparte en redes. Decisión del negocio,
 * `DEC-238`. Las dos se enlazan entre sí y `/entrar` queda para quien ya tiene
 * cuenta.
 *
 * ### Si no hay portada, se va al acceso
 *
 * Y no a un 404. Una instalación recién migrada y sin sembrar no tiene contenido
 * que enseñar, y eso **no puede ser un error en la cara de un visitante**: es
 * exactamente lo que había antes de esta iteración. Nada bloquea (`DEC-190`).
 */
final class PortadaController
{
    public function marcas(): View|RedirectResponse
    {
        return $this->pintar(Landing::MARCAS);
    }

    public function creadores(): View|RedirectResponse
    {
        return $this->pintar(Landing::CREADORES);
    }

    /** El «gracias» de la postulación, que no puede caducar ni exigir sesión. */
    public function gracias(): View|RedirectResponse
    {
        $pagina = Landing::portada(Landing::CREADORES);

        if ($pagina === null) {
            return redirect()->route('acceso');
        }

        return view('publico.gracias', ['pagina' => $pagina]);
    }

    private function pintar(string $code): View|RedirectResponse
    {
        $pagina = Landing::portada($code);

        if ($pagina === null || (int) $pagina->is_published !== 1) {
            return redirect()->route('acceso');
        }

        return view('publico.landing', [
            'pagina' => $pagina,
            // La marca se pasa AQUI y no se hereda de un compositor sobre la
            // plantilla: `verificar-pantallas.py` lo caza --y con razon--,
            // porque un compositor comodin esconde de quien lee el controlador
            // que la vista necesita este dato.
            'marca' => Marca::datos(),
            'esDeCreadores' => $code === Landing::CREADORES,
            // 9.21c: las DOS portadas tienen formulario --postular y contactar--
            // asi que los paises hacen falta siempre.
            'paises' => DB::table('countries')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
