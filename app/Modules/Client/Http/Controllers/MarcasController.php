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

        // Los DOS lados a `int` y ordenados, y sin `collect()` de por medio.
        //
        // `collect($datos['categorias'] ?? [])` no compilaba en el analisis
        // estatico --`$datos` es `array<string, mixed>`, asi que el argumento es
        // `mixed` y PHPStan no puede resolver `TKey`/`TValue`--, y arreglarlo
        // destapo algo peor: `pluck()` devuelve lo que da el driver, que puede
        // ser `'1'`, mientras el otro lado ya venia casteado a `1`. Con `!==`,
        // `['1'] !== [1]` es SIEMPRE cierto: la bitacora anotaba un cambio de
        // categorias cada vez que se guardaba la marca, hubiera cambiado o no.
        /** @var list<mixed> $enviadas */
        $enviadas = (array) ($datos['categorias'] ?? []);

        $antes = array_map(
            static fn ($c): int => (int) $c,
            DB::table('client_brand_categories')
                ->where('client_brand_id', $fila->id)->pluck('category_id')->all(),
        );
        $despues = array_map(static fn ($c): int => (int) $c, $enviadas);

        sort($antes);
        sort($despues);

        $cambios = [];
        foreach (['name', 'website', 'status'] as $campo) {
            if ((string) ($fila->{$campo} ?? '') !== (string) ($datos[$campo] ?? '')) {
                $cambios[$campo] = ['antes' => $fila->{$campo}, 'despues' => $datos[$campo] ?? null];
            }
        }
        if ($this->trajoCategorias($request) && $antes !== $despues) {
            $cambios['categorias'] = ['antes' => $antes, 'despues' => $despues];
        }

        if ($cambios === []) {
            return redirect()->route('clientes.show', $uuid)->with('aviso', 'No cambio nada.');
        }

        $trajo = $this->trajoCategorias($request);

        DB::transaction(function () use ($fila, $datos, $trajo): void {
            $actualizacion = [
                'name' => $datos['name'],
                'website' => $datos['website'] ?? null,
                'status' => $datos['status'],
                'updated_at' => now(),
            ];

            // El slug se rehace SOLO si cambió el nombre. Un slug estable es
            // parte de la identidad de la marca: si algún día está en una URL,
            // cambiarlo por editar el sitio web rompería el enlace.
            //
            // Cuando se rehace, lo hace el servicio: calcular el slug y
            // escribirlo son dos sentencias, y entre las dos cabe otra
            // peticion. El reintento vive en `Marcas` para que el alta y la
            // edicion no se arreglen por separado (`T-17`).
            if ((string) $fila->name !== (string) $datos['name']) {
                Marcas::actualizarConSlug((int) $fila->id, (string) $datos['name'], $actualizacion);
            } else {
                DB::table('client_brands')->where('id', $fila->id)->update($actualizacion);
            }

            // Sólo se tocan las categorías si el formulario las mandó.
            //
            // `sincronizarCategorias()` empieza por un `delete()`, así que una
            // petición que no incluyera `categorias` las borraba TODAS, y sin
            // categorías no hay detección de conflictos de marca
            // (`BR-CAMPAIGN-007`) para esa marca. Con casillas eso no es
            // rebuscado: un HTML no manda nada cuando no hay ninguna marcada.
            //
            // El formulario manda `categorias_enviadas` siempre, así que
            // «ninguna marcada» y «el campo no venía» dejan de ser lo mismo.
            if ($trajo) {
                $this->sincronizarCategorias((int) $fila->id, $datos['categorias'] ?? []);
            }
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

    /**
     * ¿El formulario mandó la sección de categorías?
     *
     * Un `<input type="checkbox">` sin marcar no se manda, así que la ausencia
     * de `categorias` es ambigua: puede ser «ninguna» o «no venía el campo». El
     * formulario manda un testigo oculto para deshacer esa ambigüedad.
     */
    private function trajoCategorias(GuardarMarcaRequest $request): bool
    {
        return $request->boolean('categorias_enviadas');
    }

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
