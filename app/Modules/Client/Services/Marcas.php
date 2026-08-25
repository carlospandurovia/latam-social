<?php

declare(strict_types=1);

namespace App\Modules\Client\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta de marcas, y el slug que las identifica (iteración 4.2).
 *
 * ### Por qué existe la marca aparte del cliente (`DEC-074`)
 *
 * La pregunta salió en revisión y merece estar escrita: *«¿clientes y marcas no
 * son lo mismo?»*. Para muchos clientes lo parecen, y el modelo dice que no por
 * tres motivos que se ven en el esquema:
 *
 * 1. **Una organización tiene N marcas.** `uq_cb_name` es
 *    `(client_organization_id, name)`: el nombre de marca solo es único *dentro*
 *    de su cliente. Alicorp factura como Alicorp; la campaña es de Primor o de
 *    Sapolio.
 * 2. **El conflicto de marca es por MARCA, no por cliente** (`BR-CAMPAIGN-007`).
 *    Las categorías cuelgan de `client_brand_categories`. Un creador puede tener
 *    conflicto con una marca de cuidado personal y estar libre para una de
 *    limpieza **del mismo cliente**. Si fueran una sola cosa, ese matiz no se
 *    podría ni expresar.
 * 3. **La factura no menciona la marca.** `invoices` apunta a
 *    `client_organization_id` y a `client_tax_profile_id`: la razón social y el
 *    identificador fiscal salen del cliente. Una marca no tiene RUC.
 *
 * ### Y por qué, aun así, el caso simple no cuesta dos formularios
 *
 * Que el modelo distinga no obliga a que la pantalla lo imponga. Para un cliente
 * de una sola marca —que serán la mayoría al principio— pedir dos altas es
 * papeleo puro. Por eso al dar de alta un cliente se crea **su primera marca con
 * el mismo nombre**, visible y editable, y se le dice al operador que existe.
 *
 * El caso simple cuesta un formulario; el complejo sigue siendo posible; y nadie
 * tiene que entender la diferencia hasta que le hace falta.
 */
final class Marcas
{
    /**
     * Crea una marca y devuelve su id.
     *
     * @param array<string, mixed> $extra
     */
    public static function crear(int $clienteId, string $nombre, array $extra = []): int
    {
        return (int) DB::table('client_brands')->insertGetId($extra + [
            'uuid' => (string) Str::uuid(),
            'client_organization_id' => $clienteId,
            'name' => $nombre,
            'slug' => self::slugUnico($nombre),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un slug que no choque con ninguno existente.
     *
     * `uq_cb_slug` es único **globalmente**, no por cliente: dos clientes
     * distintos no pueden tener dos marcas «primor». Es deliberado —el slug es
     * lo que acabará en una URL pública— pero significa que el segundo en llegar
     * necesita otro, y quien da de alta el cliente no tiene por qué saberlo ni
     * enterarse.
     *
     * Se resuelve aquí, sufijando, en vez de devolverle un error de unicidad a
     * alguien que no eligió el slug.
     */
    public static function slugUnico(string $nombre, ?int $exceptoId = null): string
    {
        $base = Str::slug($nombre);

        if ($base === '') {
            // `Str::slug('株式会社')` devuelve cadena vacía, y un slug vacío
            // choca con el siguiente vacío. Mejor algo feo que algo roto.
            $base = 'marca';
        }

        $base = mb_substr($base, 0, 130);
        $slug = $base;
        $n = 1;

        while (self::ocupado($slug, $exceptoId)) {
            $n++;
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private static function ocupado(string $slug, ?int $exceptoId): bool
    {
        $consulta = DB::table('client_brands')->where('slug', $slug);

        if ($exceptoId !== null) {
            $consulta->where('id', '<>', $exceptoId);
        }

        return $consulta->exists();
    }
}
