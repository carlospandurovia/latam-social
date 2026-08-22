<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CatalogosController
{
    /**
     * Lista blanca explícita. El nombre de la tabla viene de la URL, así que
     * NUNCA se interpola directamente: solo se aceptan estas claves, y las
     * columnas se fijan aquí, no se descubren del esquema.
     *
     * @var array<string, array{titulo: string, columnas: list<string>, orden: string}>
     */
    private const CATALOGOS = [
        'countries' => [
            'titulo' => 'Países',
            'columnas' => ['iso2', 'iso3', 'name', 'phone_code', 'default_currency_code', 'timezone', 'is_active'],
            'orden' => 'name',
        ],
        'currencies' => [
            'titulo' => 'Monedas',
            'columnas' => ['code', 'name', 'symbol', 'decimal_places', 'is_active'],
            'orden' => 'code',
        ],
        'categories' => [
            'titulo' => 'Categorías',
            'columnas' => ['code', 'depth', 'min_age', 'is_active'],
            'orden' => 'code',
        ],
        'platforms' => [
            'titulo' => 'Redes sociales',
            'columnas' => ['code', 'name', 'url_pattern', 'is_active'],
            'orden' => 'code',
        ],
        'content_formats' => [
            'titulo' => 'Formatos de contenido',
            'columnas' => ['code', 'default_permanence_days', 'is_active'],
            'orden' => 'code',
        ],
        'languages' => [
            'titulo' => 'Idiomas',
            'columnas' => ['code', 'name', 'is_active'],
            'orden' => 'code',
        ],
    ];

    public function show(string $catalogo): View
    {
        if (! array_key_exists($catalogo, self::CATALOGOS)) {
            throw new NotFoundHttpException("Catálogo desconocido: {$catalogo}");
        }

        $config = self::CATALOGOS[$catalogo];

        return view('catalogos.index', [
            'titulo' => $config['titulo'],
            'columnas' => $config['columnas'],
            'filas' => DB::table($catalogo)
                ->orderBy($config['orden'])
                ->paginate(25)
                ->withPath(route('catalogos.show', $catalogo)),
        ]);
    }
}
