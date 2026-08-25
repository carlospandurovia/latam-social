<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Controllers;

use App\Modules\Client\Http\Requests\GuardarMarcaRequest;
use App\Modules\Client\Services\Marcas;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Marcas de un cliente (iteración 4.2).
 *
 * Una campaña se hace **para una marca**, no para el cliente: `campaigns` tiene
 * `client_brand_id NOT NULL`. Sin marcas no hay campaña, y por eso el alta de
 * cliente ya crea la primera (`DEC-074`).
 *
 * ### Las categorías no son decoración
 *
 * `client_brand_categories` es lo que alimenta `BR-CAMPAIGN-007`: un creador con
 * conflicto de marca activo —competidor, exclusividad vigente, categoría
 * prohibida— no puede ser invitado sin anulación explícita. Ese cotejo se hace
 * **por categoría de la marca**, no por cliente.
 *
 * O sea que dejar una marca sin categorías no es dejar un campo vacío: es apagar
 * la detección de conflictos para ella. La pantalla lo dice con esas palabras.
 */
final class MarcasController
{
    public function create(string $uuid): View
    {
        return view('marcas.form', [
            'cliente' => $this->cliente($uuid),
            'marca' => null,
            'categorias' => $this->categorias(),
            'elegidas' => [],
        ]);
    }

    public function edit(string $uuid, string $marca): View
    {
        $cliente = $this->cliente($uuid);
        $fila = $this->marca($cliente, $marca);

        return view('marcas.form', [
            'cliente' => $cliente,
            'marca' => $fila,
            'categorias' => $this->categorias(),
            'elegidas' => DB::table('client_brand_categories')
                ->where('client_brand_id', $fila->id)
                ->pluck('category_id')->all(),
        ]);
    }

    public function store(GuardarMarcaRequest $request, string $uuid): RedirectResponse
    {
        $cliente = $this->cliente($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $marcaId = 0;

        DB::transaction(function () use ($cliente, $datos, &$marcaId): void {
            $marcaId = Marcas::crear($cliente->id, (string) $datos['name'], [
                'website' => $datos['website'] ?? null,
                'status' => $datos['status'],
            ]);

            $this->sincronizarCategorias($marcaId, $datos['categorias'] ?? []);
        });

        Bitacora::registrar(
            accion: 'client_brand.created',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: ['marca' => ['antes' => null, 'despues' => $datos['name']]],
        );

        return redirect()->route('clientes.show', $uuid)
            ->with('exito', "Marca «{$datos['name']}» dada de alta.");
    }

    public function update(GuardarMarcaRequest $request, string $uuid, string $marca): RedirectResponse
    {
        $cliente = $this->cliente($uuid);
        $fila = $this->marca($cliente, $marca);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $antes = DB::table('client_brand_categories')
            ->where('client_brand_id', $fila->id)->pluck('category_id')->sort()->values()->all();
        $despues = collect($datos['categorias'] ?? [])->map(fn ($c): int => (int) $c)->sort()->values()->all();

        $cambios = [];
        foreach (['name', 'website', 'status'] as $campo) {
            if ((string) ($fila->{$campo} ?? '') !== (string) ($datos[$campo] ?? '')) {
                $cambios[$campo] = ['antes' => $fila->{$campo}, 'despues' => $datos[$campo] ?? null];
            }
        }
        if ($antes !== $despues) {
            $cambios['categorias'] = ['antes' => $antes, 'despues' => $despues];
        }

        if ($cambios === []) {
            return redirect()->route('clientes.show', $uuid)->with('aviso', 'No cambio nada.');
        }

        DB::transaction(function () use ($fila, $datos): void {
            $actualizacion = [
                'name' => $datos['name'],
                'website' => $datos['website'] ?? null,
                'status' => $datos['status'],
                'updated_at' => now(),
            ];

            // El slug se rehace SOLO si cambió el nombre. Un slug estable es
            // parte de la identidad de la marca: si algún día está en una URL,
            // cambiarlo por editar el sitio web rompería el enlace.
            if ((string) $fila->name !== (string) $datos['name']) {
                $actualizacion['slug'] = Marcas::slugUnico((string) $datos['name'], (int) $fila->id);
            }

            DB::table('client_brands')->where('id', $fila->id)->update($actualizacion);

            $this->sincronizarCategorias((int) $fila->id, $datos['categorias'] ?? []);
        });

        Bitacora::registrar(
            accion: 'client_brand.updated',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: $cambios,
        );

        return redirect()->route('clientes.show', $uuid)->with('exito', 'Marca actualizada.');
    }

    // ------------------------------------------------------------------ apoyo

    /** @param array<int, mixed> $categorias */
    private function sincronizarCategorias(int $marcaId, array $categorias): void
    {
        DB::table('client_brand_categories')->where('client_brand_id', $marcaId)->delete();

        foreach (array_unique(array_map('intval', $categorias)) as $categoriaId) {
            DB::table('client_brand_categories')->insert([
                'client_brand_id' => $marcaId,
                'category_id' => $categoriaId,
                'created_at' => now(),
            ]);
        }
    }

    private function cliente(string $uuid): object
    {
        $cliente = DB::table('client_organizations')->where('uuid', $uuid)
            ->first(['id', 'uuid', 'commercial_name']);

        if ($cliente === null) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        return $cliente;
    }

    private function marca(object $cliente, string $uuid): object
    {
        $marca = DB::table('client_brands')
            ->where('uuid', $uuid)
            ->where('client_organization_id', $cliente->id)
            ->first();

        if ($marca === null) {
            // Se comprueba que sea DE ESTE cliente, no solo que exista: si no,
            // la URL de un cliente serviria para editar la marca de otro.
            throw new NotFoundHttpException('Marca no encontrada en este cliente.');
        }

        return $marca;
    }

    /** @return Collection<int, \stdClass> */
    private function categorias(): Collection
    {
        return DB::table('categories')->orderBy('code')->get(['id', 'code']);
    }
}
