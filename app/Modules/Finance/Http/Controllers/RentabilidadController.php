<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Costos;
use App\Modules\Finance\Services\Rentabilidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La rentabilidad (9.10).
 *
 * Dos pantallas y un solo permiso, `campaign.view_margin`: la lista contesta
 * **cuáles pierden dinero** y la ficha contesta **por qué ésta**.
 *
 * El permiso se comprueba en la ruta y **el dato no se calcula si no se puede
 * ver** — que es el criterio de `7.7`: no se calcula y luego se esconde en la
 * plantilla, porque una plantilla se edita y un `@can` se borra sin querer.
 * Aquí la protección es más fuerte todavía: la pantalla entera es del permiso.
 */
final class RentabilidadController
{
    public function index(Request $peticion): View
    {
        $estado = (string) $peticion->query('estado', '');

        return view('rentabilidad.index', [
            'grupos' => Rentabilidad::listado(['estado' => $estado]),
            'estado' => $estado,
            'estados' => DB::table('campaigns')->distinct()->orderBy('status')->pluck('status'),
        ]);
    }

    public function show(string $uuid): View
    {
        $campana = DB::table('campaigns as c')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'c.billing_legal_entity_id')
            ->where('c.uuid', $uuid)
            ->first(['c.id', 'c.uuid', 'c.code', 'c.name', 'c.status', 'c.currency_code',
                'c.revenue_amount', 'c.is_gratis', 'c.starts_on', 'c.ends_on',
                'b.name as marca', 'le.legal_name as sociedad']);

        if ($campana === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return view('rentabilidad.show', [
            'campana' => $campana,
            'cuenta' => Rentabilidad::deUnaCampana($campana),
            // El detalle del gasto, para que «gasto operativo: 3.200» se pueda
            // abrir sin salir de la pantalla: un margen que no cuadra se
            // discute mirando de qué está hecho.
            'gastos' => Costos::deUnaCampana((int) $campana->id),
            'tipos' => Costos::TIPOS,
        ]);
    }
}
