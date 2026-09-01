<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Client\Services\Prospectos;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El contacto de las marcas (iteración 9.21c).
 *
 * ### Lo que fija
 *
 * Que **un contacto no se pierde**. Es la razón de que esto sea una tabla y no
 * un correo: hoy el correo está en «log» —no sale del servidor— y una
 * instalación con el SMTP mal configurado perdería cada contacto sin que nadie
 * se entere. Una fila no se pierde y se puede contar.
 *
 * Y que **descartar no es borrar**: exige un motivo escrito y la fila se queda,
 * porque «¿cuántos descartamos y por qué?» es lo único que permite darse cuenta
 * de que se estaba descartando mal.
 */
final class ProspectosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    // ---------------------------------------------------------- el formulario

    /** **La que más importa.** Escribir por la portada deja una fila. */
    public function test_el_contacto_de_la_portada_queda_escrito(): void
    {
        $this->enviar()->assertRedirect(route('contacto.gracias'));

        $this->assertDatabaseHas('client_leads', [
            'company_name' => 'Marca Ejemplo',
            // En minúsculas: el correo es la llave de `uq_clead_abierto`.
            'email' => 'contacto@ejemplo.pe',
            'source' => 'landing',
            'status' => 'new',
        ]);
    }

    /** Escribir dos veces no duplica la marca en la bandeja. */
    public function test_dos_contactos_con_el_mismo_correo_no_se_duplican(): void
    {
        $this->enviar()->assertRedirect(route('contacto.gracias'));

        $this->enviar()
            ->assertRedirect()
            ->assertSessionHas('aviso', fn (string $a): bool => str_contains($a, 'estamos mirando'));

        $this->assertSame(1, DB::table('client_leads')->count());
    }

    /**
     * Pero cerrado deja el hueco libre: el año que viene es un contacto nuevo.
     *
     * Es lo que separa `uq_clead_abierto` de una única sobre el correo a secas,
     * y la diferencia se nota justo cuando alguien vuelve.
     */
    public function test_un_contacto_cerrado_deja_volver_a_escribir(): void
    {
        $this->enviar();
        $uuid = (string) DB::table('client_leads')->value('uuid');

        Prospectos::mover($uuid, 'discarded', 'No encaja: no tienen presupuesto este año.',
            (int) $this->usuarioCon('campaign_manager')->id);

        $this->enviar()->assertRedirect(route('contacto.gracias'));

        $this->assertSame(2, DB::table('client_leads')->count());
    }

    public function test_el_campo_trampa_no_escribe_nada(): void
    {
        $this->enviar(['empresa_2' => 'me delaté solo'])
            ->assertRedirect(route('contacto.gracias'));

        $this->assertSame(0, DB::table('client_leads')->count());
    }

    public function test_una_web_por_ftp_se_rechaza_con_palabras(): void
    {
        $this->enviar(['website' => 'ftp://ejemplo.pe'])->assertSessionHasErrors('website');
    }

    // -------------------------------------------------------------- la bandeja

    public function test_la_bandeja_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('creator'))
            ->get(route('prospectos.index'))->assertStatus(403);
    }

    public function test_el_contacto_aparece_en_la_bandeja(): void
    {
        $this->enviar();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('prospectos.index'))
            ->assertOk()
            ->assertSee('Marca Ejemplo')
            ->assertSee('contacto@ejemplo.pe');
    }

    public function test_se_mueve_a_contactado_y_queda_quien_lo_movio(): void
    {
        $this->enviar();
        $uuid = (string) DB::table('client_leads')->value('uuid');
        $usuario = $this->usuarioCon('campaign_manager');

        $this->actingAs($usuario)
            ->post(route('prospectos.mover', $uuid), ['estado' => 'contacted'])
            ->assertRedirect();

        $fila = DB::table('client_leads')->where('uuid', $uuid)->first();

        $this->assertSame('contacted', $fila->status);
        $this->assertSame((int) $usuario->id, (int) $fila->reviewed_by_user_id);
        $this->assertNotNull($fila->reviewed_at);
    }

    /** Descartar sin motivo no se puede, y se dice con palabras. */
    public function test_descartar_exige_un_motivo(): void
    {
        $this->enviar();
        $uuid = (string) DB::table('client_leads')->value('uuid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/por que/');

        Prospectos::mover($uuid, 'discarded', 'no', (int) $this->usuarioCon('campaign_manager')->id);
    }

    /** Y un contacto no se borra: descartarlo es la forma de decir que no. */
    public function test_un_contacto_no_se_borra(): void
    {
        $this->enviar();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no se borra/');

        DB::table('client_leads')->delete();
    }

    /** Convertir exige decir en qué cliente, y por eso no es un botón suelto. */
    public function test_convertir_exige_decir_en_que_cliente(): void
    {
        $this->enviar();
        $uuid = (string) DB::table('client_leads')->value('uuid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/en que cliente/');

        // `mover()` a `converted` no vale: hay que pasar por `convertir()`, que
        // exige el cliente. Un boton suelto crearia contactos «convertidos» que
        // no dicen en que.
        Prospectos::mover($uuid, 'converted', null, (int) $this->usuarioCon('campaign_manager')->id);
    }

    public function test_se_enlaza_con_el_cliente_en_que_se_convirtio(): void
    {
        $this->enviar();
        $uuid = (string) DB::table('client_leads')->value('uuid');
        $clienteId = $this->clienteDePrueba();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('prospectos.convertir', $uuid), ['client_organization_id' => $clienteId])
            ->assertRedirect();

        $fila = DB::table('client_leads')->where('uuid', $uuid)->first();

        $this->assertSame('converted', $fila->status);
        $this->assertSame($clienteId, (int) $fila->client_organization_id);
    }

    public function test_mover_queda_en_la_bitacora(): void
    {
        $this->enviar();
        $uuid = (string) DB::table('client_leads')->value('uuid');
        $id = (int) DB::table('client_leads')->where('uuid', $uuid)->value('id');

        Prospectos::mover($uuid, 'qualified', null, (int) $this->usuarioCon('campaign_manager')->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'client_lead.moved',
            'entity_type' => 'client_lead',
            'entity_id' => $id,
        ]);
    }

    // ------------------------------------------------------------------ apoyo

    /** @param array<string, mixed> $cambios */
    private function enviar(array $cambios = []): TestResponse
    {
        return $this->post(route('contacto'), array_merge([
            'company_name' => 'Marca Ejemplo',
            'contact_name' => 'Luis Torres',
            'email' => 'CONTACTO@ejemplo.pe',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'message' => 'Queremos una campaña para el lanzamiento de abril.',
        ], $cambios));
    }

    private function clienteDePrueba(): int
    {
        return (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'commercial_name' => 'Marca Ejemplo S.A.',
            'client_code' => 'CLI-P921',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
