<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Client\Services\Contactos;
use App\Modules\Client\Services\Marcas;
use App\Shared\Auth\Permisos;
use App\Shared\Database\Choque;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Las dos carreras de `T-17`, y lo que se puede comprobar de ellas.
 *
 * ### Lo que estas pruebas NO son
 *
 * No reproducen la concurrencia. Una prueba de PHPUnit corre en una conexión y,
 * con `RefreshDatabase`, dentro de una transacción abierta: una segunda conexión
 * no vería nada de lo que la prueba ha escrito. Escribir algo que *pareciera*
 * una prueba de concurrencia y no lo fuera sería peor que no tenerla, porque
 * saldría verde igual con el arreglo quitado.
 *
 * Lo que sí se comprueba es cada mitad del arreglo por separado, y de forma que
 * **quitarlo ponga algo en rojo**:
 *
 * | Mitad | Lo que fija la prueba |
 * |---|---|
 * | Contactos | que el camino de relevo pide `FOR UPDATE` sobre la fila del cliente |
 * | Contactos | que sin transacción se niega en voz alta, en vez de fingir |
 * | Marcas | que el recálculo evita lo ya probado, que es lo que hace converger al reintento |
 * | Marcas | que un `1062` de verdad no mata la transacción de fuera |
 *
 * ### Y lo que sigue sin comprobarse: la carrera de verdad
 *
 * Nada ejecuta hoy dos peticiones a la vez contra este código. Se puede hacer
 * —una suite de `tools/pruebas/` sí abre dos clientes contra el mismo motor, y
 * con `innodb_lock_wait_timeout` bajo se comprueba que el segundo espera al
 * primero sin depender de un `sleep`— pero es una herramienta nueva, no un
 * arreglo, y meterla aquí sería colar una iteración dentro de otra. Queda como
 * `T-27`.
 *
 * Se escribe aquí, y no en el mensaje de un commit, porque quien lea estas
 * pruebas dentro de seis meses tiene que saber **qué no dicen**.
 */
final class CarrerasTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
    }

    // ------------------------------------------------- contactos: el bloqueo

    /**
     * **La prueba de la mitad de contactos.**
     *
     * El `UPDATE` que baja al principal anterior bloquea su fila cuando el
     * puesto está ocupado, y **no bloquea nada** cuando está libre: afecta a
     * cero filas. Ése es justo el caso normal —el primer contacto de un tipo— y
     * es donde dos peticiones simultáneas se cuelan las dos.
     *
     * Se comprueba que, aun con el puesto libre, la maniobra pide un bloqueo
     * sobre la fila del cliente, que siempre existe. Se mira el SQL emitido
     * porque el efecto de un bloqueo —que la otra petición espere— es
     * precisamente lo que una sola conexión no puede observar.
     */
    public function test_subir_a_principal_bloquea_la_fila_del_cliente_aunque_el_puesto_este_libre(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $sql = $this->sqlDe(function () use ($gestor, $uuid): void {
            $this->actingAs($gestor)->post("/backoffice/clientes/{$uuid}/contactos", $this->contacto([
                'is_primary' => '1',
            ]))->assertSessionHas('exito');
        });

        $bloqueos = array_values(array_filter(
            $sql,
            static fn (string $q): bool => str_contains($q, 'client_organizations')
                && str_contains(strtolower($q), 'for update'),
        ));

        $this->assertNotEmpty(
            $bloqueos,
            "el relevo tiene que bloquear la fila del cliente; SQL emitido:\n".implode("\n", $sql),
        );

        // Y el puesto estaba libre: si hubiera estado ocupado, el `UPDATE`
        // habria bloqueado solo y la prueba no probaria lo que dice probar. Es
        // la leccion de 4.5 —una asercion verde por el motivo equivocado—.
        $this->assertSame(1, DB::table('contacts')->where('is_primary', 1)->count());
    }

    /**
     * Sin transacción el bloqueo se suelta antes del `INSERT`, así que la
     * carrera vuelve. Se comprueba en vez de comentarse (`T-24`).
     *
     * `RefreshDatabase` deja siempre una transacción abierta, así que para
     * llegar al nivel 0 hay que salir de ella a propósito y volver a entrar: la
     * teardown de `RefreshDatabase` espera encontrarse una abierta.
     */
    public function test_sin_transaccion_el_relevo_se_niega_en_voz_alta(): void
    {
        $this->assertSame(1, DB::transactionLevel(), 'la premisa de esta prueba');

        DB::rollBack();

        try {
            $this->assertSame(0, DB::transactionLevel());

            $this->expectException(LogicException::class);

            Contactos::crear(1, $this->contacto(['is_primary' => true]));
        } finally {
            DB::beginTransaction();
        }
    }

    // ----------------------------------------------------- marcas: el reintento

    /**
     * **La prueba de la mitad de marcas.**
     *
     * Lo que hace converger al reintento no es volver a preguntar —en
     * `REPEATABLE READ` la respuesta es la misma— sino decirle a `slugUnico()`
     * qué se probó ya. Sin `evitando`, el segundo intento devuelve el mismo
     * slug y choca las tres veces.
     */
    public function test_el_recalculo_salta_lo_que_ya_choco(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $this->crearCliente($gestor);

        // El alta de cliente ya creo la marca «ACME» con slug `acme`.
        $this->assertTrue(DB::table('client_brands')->where('slug', 'acme')->exists());

        // Sin pistas, el siguiente libre es `acme-2`.
        $this->assertSame('acme-2', Marcas::slugUnico('ACME'));

        // Diciendole que `acme-2` ya se intento y choco, pasa al siguiente.
        $this->assertSame('acme-3', Marcas::slugUnico('ACME', evitando: ['acme-2']));
        $this->assertSame('acme-4', Marcas::slugUnico('ACME', evitando: ['acme-2', 'acme-3']));
    }

    /**
     * **Un `1062` de verdad, y la transacción sobrevive.**
     *
     * De esto depende que el alta de cliente no pierda el cliente: el `INSERT`
     * de la marca va dentro de la misma transacción que el `INSERT` del
     * cliente, y antes de `T-17` un choque de slug se llevaba los dos por
     * delante —el operador veía un 500 y el cliente no existía—.
     *
     * En InnoDB un `1062` deshace la **sentencia**, no la transacción. Aquí se
     * provoca uno auténtico contra el motor, se absorbe, y se comprueba que lo
     * escrito antes sigue ahí y que lo escrito después también.
     */
    public function test_un_choque_real_no_mata_la_transaccion_de_fuera(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $this->crearCliente($gestor);

        $clienteId = (int) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('id');
        $antes = DB::table('client_brands')->count();

        $intentos = 0;

        $id = Choque::reintentar('uq_cb_slug', function (int $n) use ($clienteId, &$intentos): int {
            $intentos = $n;

            return (int) DB::table('client_brands')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'client_organization_id' => $clienteId,
                'name' => 'Segunda '.$n,
                // El primer intento repite `acme` a proposito: choque autentico
                // del motor, no una excepcion fabricada.
                'slug' => $n === 1 ? 'acme' : 'acme-'.$n,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertSame(2, $intentos, 'el primer intento tenia que chocar');
        $this->assertGreaterThan(0, $id);

        // La transaccion sigue viva: se ve lo de antes y lo de despues.
        $this->assertSame($antes + 1, DB::table('client_brands')->count());
        $this->assertTrue(DB::table('client_organizations')->where('id', $clienteId)->exists());
        $this->assertSame('acme-2', DB::table('client_brands')->where('id', $id)->value('slug'));
    }

    /** Y el alta normal, que es la que tiene que seguir saliendo limpia. */
    public function test_el_alta_de_cliente_sigue_creando_su_marca(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $clienteId = (int) DB::table('client_organizations')->where('uuid', $uuid)->value('id');

        $this->assertSame('acme', DB::table('client_brands')
            ->where('client_organization_id', $clienteId)->value('slug'));
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * El SQL que emite el bloque, en orden.
     *
     * @param callable(): void $bloque
     * @return list<string>
     */
    private function sqlDe(callable $bloque): array
    {
        $consultas = [];

        DB::listen(static function ($evento) use (&$consultas): void {
            $consultas[] = $evento->sql;
        });

        try {
            $bloque();
        } finally {
            DB::flushQueryLog();
        }

        return $consultas;
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function contacto(array $cambios = []): array
    {
        return array_merge([
            'full_name' => 'Ana Torres',
            'contact_email' => 'ana@acme.test',
            'phone' => '999888777',
            'position' => 'Gerente de marketing',
            'contact_type' => 'commercial',
            'status' => 'active',
        ], $cambios);
    }

    private function crearCliente(User $quien): string
    {
        $this->actingAs($quien)->post('/backoffice/clientes', [
            'commercial_name' => 'ACME',
            'client_code' => 'ACME-01',
            'country_id' => $this->paisPE,
            'status' => 'prospect',
        ]);

        return (string) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('uuid');
    }
}
