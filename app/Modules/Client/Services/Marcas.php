<?php

declare(strict_types=1);

namespace App\Modules\Client\Services;

use App\Shared\Database\Choque;
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
        // `client_brands.name` son 120 y `client_organizations.commercial_name`
        // son 160. El alta de cliente crea su primera marca con el mismo nombre
        // (`DEC-074`), asi que un cliente con nombre comercial de 121 a 160
        // caracteres —una razon social larga— reventaba con
        // `1406 Data too long for column 'name'`. Y como el alta va en una
        // transaccion, **se perdia el cliente entero** y el operador veia un 500.
        //
        // Se recorta aqui y no se valida a 120 arriba: el nombre comercial del
        // cliente PUEDE ser mas largo que el de una marca, son campos distintos.
        // El mensaje de exito ya nombra la marca creada, asi que el recorte se ve.
        $nombre = mb_substr($nombre, 0, 120);

        // El slug lo calcula el sistema, no la persona: si choca, se recalcula
        // y se vuelve a intentar en silencio (`T-17`). Se absorbe SOLO
        // `uq_cb_slug`; `uq_cb_name` —el operador dando de alta dos veces la
        // misma marca del mismo cliente— tiene que llegar arriba y leerse.
        //
        // Y esto va dentro de la transaccion del alta de cliente. Comprobado
        // contra el motor: en InnoDB un `1062` deshace la SENTENCIA, no la
        // transaccion, asi que el reintento no se lleva el cliente por delante.
        // Antes si: un doble clic devolvia un 500 y el cliente no existia.
        $probados = [];

        return Choque::reintentar('uq_cb_slug', function () use (
            $extra, $clienteId, $nombre, &$probados
        ): int {
            $slug = self::slugUnico($nombre, evitando: $probados);
            $probados[] = $slug;

            return (int) DB::table('client_brands')->insertGetId($extra + [
                'uuid' => (string) Str::uuid(),
                'client_organization_id' => $clienteId,
                'name' => $nombre,
                'slug' => $slug,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Escribe columnas en una marca **rehaciendo su slug**, con el mismo
     * reintento que el alta (`T-17`).
     *
     * Existe para que la carrera del slug se resuelva en UN sitio. La edición
     * de marca tiene exactamente el mismo hueco que el alta —calcular el slug y
     * escribirlo son dos sentencias— y dejar la reacción en el controlador
     * garantizaba que las dos se arreglaran por separado, o que sólo una lo
     * hiciera.
     *
     * @param array<string, mixed> $columnas todo menos el slug, que lo pone esto
     */
    public static function actualizarConSlug(int $marcaId, string $nombre, array $columnas): void
    {
        $probados = [];

        Choque::reintentar('uq_cb_slug', function () use ($marcaId, $nombre, $columnas, &$probados): void {
            $slug = self::slugUnico($nombre, $marcaId, evitando: $probados);
            $probados[] = $slug;

            DB::table('client_brands')->where('id', $marcaId)->update($columnas + ['slug' => $slug]);
        });
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
     *
     * ### `$evitando`, y por qué no basta con volver a preguntar (`T-17`)
     *
     * Entre este `SELECT` y el `INSERT` que viene después cabe otra petición.
     * Si la otra gana, ésta choca contra `uq_cb_slug`. La reacción evidente
     * —volver a calcular y reintentar— **no funciona**, y el motivo se comprobó
     * contra el motor antes de escribir esto:
     *
     * ```
     * A: START TRANSACTION;  SELECT ... WHERE slug='acme-2';   -> 0 filas
     * B: INSERT slug='acme-2';  COMMIT;
     * A: INSERT slug='acme-2';  -> 1062
     * A: SELECT ... WHERE slug='acme-2';   -> 0 filas   <-- SIGUE sin verla
     * ```
     *
     * En `REPEATABLE READ` la lectura de consistencia sigue viendo la foto del
     * principio de la transacción, así que recalcular devuelve **el mismo
     * slug** y el reintento choca otras dos veces. Una lectura con bloqueo sí
     * la ve, pero un `FOR UPDATE` sobre una fila que no existe toma un bloqueo
     * de hueco, y dos peticiones con huecos compatibles acaban en interbloqueo
     * al insertar: se cambia un `1062` por un `1213`.
     *
     * Por eso el que reintenta **le dice a esta función qué ya probó**. No
     * necesita ver la fila de la otra transacción para saber que ese slug está
     * cogido: acaba de estrellarse contra él.
     *
     * @param list<string> $evitando slugs que ya se intentaron y chocaron
     */
    public static function slugUnico(string $nombre, ?int $exceptoId = null, array $evitando = []): string
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

        while (in_array($slug, $evitando, true) || self::ocupado($slug, $exceptoId)) {
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
