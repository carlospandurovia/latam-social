<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Landing;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Sitio;
use Illuminate\Http\RedirectResponse;
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

        // L-1: ya no se comprueba `is_published` aqui. Lo hace `portada()`, que
        // es donde tiene que estar para que el `sitemap.xml` obedezca la misma
        // regla sin volver a escribirla.
        if ($pagina === null) {
            return redirect()->route('acceso');
        }

        return view('publico.landing', [
            'pagina' => $pagina,
            // L-3: la cabecera de ESTA portada, y no la de reserva que pone el
            // compositor de `layouts.publico`. Las anclas de un menu son las
            // secciones de la pagina que se esta mirando; las de la otra
            // portada no existen aqui, y un ancla que no existe no da error:
            // simplemente no pasa nada al pulsarla.
            'portadaCabecera' => $pagina,
            'navCabecera' => Landing::navegacion((int) $pagina->id)
                ->each(static function (object $seccion): void {
                    // Vacio = la misma pagina. El menu sale como `#ancla`.
                    $seccion->base = '';
                }),
            // La marca se pasa AQUI y no se hereda de un compositor sobre la
            // plantilla: `verificar-pantallas.py` lo caza --y con razon--,
            // porque un compositor comodin esconde de quien lee el controlador
            // que la vista necesita este dato.
            'marca' => Marca::datos(),
            // L-3: el WhatsApp del heroe. Va AQUI y no se hereda del compositor
            // de `layouts.publico`: un compositor sobre la plantilla no alcanza
            // a la vista que la extiende --la portada salio con «Undefined
            // variable $sitio»-- y ademas `verificar-pantallas.py` tiene razon
            // en pedirlo asi: quien lee el controlador ve que la vista lo
            // necesita.
            'sitio' => Sitio::datos(),
            'esDeCreadores' => $code === Landing::CREADORES,
            // 9.21c: las DOS portadas tienen formulario --postular y contactar--
            // asi que los paises hacen falta siempre.
            //
            // L-5 (`C-2`): la lista sale con el pais por defecto DELANTE, y ese
            // pais no es una constante --lo dice `Sitio`--. Antes era
            // `orderBy('name')` a secas, y el primero por orden alfabetico
            // resultaba ser Chile: un negocio que arranca en Peru etiquetaba mal
            // sus propios leads, en silencio, desde el primer dia.
            'paises' => Sitio::paisesParaFormulario(),
            'paisPorDefecto' => Sitio::paisPorDefecto(),
        ]);
    }
}
