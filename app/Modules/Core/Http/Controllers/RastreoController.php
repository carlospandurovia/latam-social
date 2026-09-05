<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Landing;
use App\Shared\Config\Instalacion;
use Illuminate\Http\Response;

/**
 * `robots.txt` y `sitemap.xml` (L-1).
 *
 * ### Por qué son rutas y no dos archivos en `public/`
 *
 * Porque **los dos dependen de la configuración**, y un archivo estático no.
 *
 * - El mapa lista las portadas **publicadas**. Apagar la de creadores desde el
 *   admin tiene que quitarla del mapa; con un archivo a mano, no.
 * - `robots.txt` tiene que decir *«no me rastrees»* en una instalación que **no
 *   es producción** (`9.22a`). Un servidor de pruebas indexado compite en
 *   Google con el de verdad y le roba las visitas, y eso se descubre meses
 *   después.
 *
 * ### Y por eso son públicas
 *
 * Las dos van en `tools/pruebas/RUTAS-ABIERTAS` con su motivo: un buscador no
 * tiene sesión. Lo que las hace seguras no es un permiso, es que **no aceptan
 * ningún parámetro** y sólo enseñan lo que ya es público.
 */
final class RastreoController
{
    public function robots(): Response
    {
        // En una maquina que no es la de verdad, NADA se indexa. Es la misma
        // idea de `9.22a` --lo que sale de aqui no puede confundirse con lo
        // real-- aplicada a los buscadores.
        $lineas = Instalacion::esProduccion()
            ? [
                'User-agent: *',
                'Allow: /',
                'Disallow: /backoffice/',
                'Disallow: /archivos/',
                'Disallow: /entrar',
                '',
                'Sitemap: '.route('sitemap'),
            ]
            : [
                '# Esta instalacion no es produccion: nada de lo que hay aqui debe indexarse.',
                'User-agent: *',
                'Disallow: /',
            ];

        return response(implode("\n", $lineas)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = [];

        foreach ([Landing::MARCAS => route('portada.marcas'),
            Landing::CREADORES => route('portada.creadores')] as $code => $url) {
            // Solo lo PUBLICADO. Una portada apagada desde el admin redirige al
            // acceso, y ofrecersela a un buscador seria mandarlo a una puerta
            // cerrada.
            if (Landing::portada($code) !== null) {
                $urls[] = $url;
            }
        }

        $cuerpo = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $cuerpo .= '  <url><loc>'.e($url).'</loc></url>'."\n";
        }

        return response($cuerpo.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
