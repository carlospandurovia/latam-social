<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Correlativos;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Series y correlativos (iteración 9.12).
 *
 * ### Lo que fija
 *
 * Que **un número sale una sola vez** y que **lo que sale queda escrito**. La
 * carrera de verdad —dos conexiones a la vez— no se puede probar aquí: PHPUnit
 * corre en una sola conexión y dentro de una transacción abierta, así que una
 * segunda sesión no vería nada. Eso lo demuestra
 * `tools/pruebas/9.12-correlativos.sh` contra el motor.
 *
 * Lo que sí se fija aquí es lo que la suite SQL no puede ver: que el servicio
 * usa el contador de la serie y no `MAX()+1`, que el formato sale de la longitud
 * del **tipo**, que anular exige un motivo con palabras, y que la pantalla no la
 * abre quien no tiene `legal_entity.manage`.
 */
final class CorrelativosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $sociedadId;

    private int $tipoId;

    private int $serieId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->sociedadId = $this->entidadLegal();
        $this->tipoId = (int) DB::table('document_types as dt')
            ->join('countries as c', 'c.id', '=', 'dt.country_id')
            ->where('c.iso2', 'PE')->where('dt.code', 'invoice')
            ->value('dt.id');

        $this->serieId = Correlativos::guardarSerie(null, [
            'legal_entity_id' => $this->sociedadId,
            'document_type_id' => $this->tipoId,
            'series' => 'F900',
            'next_number' => 1,
            'environment' => 'production',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    // -------------------------------------------------------------- reservar

    /** **La que más importa.** Reservar dos veces da dos números distintos. */
    public function test_dos_reservas_dan_dos_numeros(): void
    {
        $primero = Correlativos::reservar($this->serieId);
        $segundo = Correlativos::reservar($this->serieId);

        $this->assertSame(1, $primero->numero);
        $this->assertSame(2, $segundo->numero);
        $this->assertSame('F900-00000001', $primero->completo);
        $this->assertSame('F900-00000002', $segundo->completo);
    }

    /**
     * El contador de la serie avanza; el número no se calcula mirando la tabla.
     *
     * Es la diferencia entre reservar y adivinar: si el servicio usara
     * `MAX(number)+1`, anular el último devolvería su número a la circulación —y
     * entonces habría dos comprobantes con el mismo—.
     */
    public function test_anular_el_ultimo_no_devuelve_su_numero(): void
    {
        $primero = Correlativos::reservar($this->serieId);
        Correlativos::anular($primero->id, 'La peticion fallo antes de emitir nada.');

        $siguiente = Correlativos::reservar($this->serieId);

        $this->assertSame(2, $siguiente->numero);
        $this->assertSame(2, (int) DB::table('document_numbers')
            ->where('document_series_id', $this->serieId)->count());
    }

    /** El formato sale de la longitud del TIPO, no de una constante. */
    public function test_el_formato_lo_manda_el_tipo(): void
    {
        DB::table('document_types')->where('id', $this->tipoId)->update(['number_length' => 4]);

        $numero = Correlativos::reservar($this->serieId);

        $this->assertSame('F900-0001', $numero->completo);
    }

    /** Una serie apagada no da números nuevos, y lo dice con palabras. */
    public function test_una_serie_apagada_no_da_numeros(): void
    {
        DB::table('document_series')->where('id', $this->serieId)
            ->update(['is_default' => false, 'is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/apagada/');

        Correlativos::reservar($this->serieId);
    }

    /** Quien reserva queda escrito: «¿quién sacó ese número?» tiene respuesta. */
    public function test_la_reserva_guarda_quien_la_hizo(): void
    {
        $admin = $this->usuarioCon('admin');
        $numero = Correlativos::reservar($this->serieId, (int) $admin->id);

        $this->assertSame((int) $admin->id, (int) DB::table('document_numbers')
            ->where('id', $numero->id)->value('reserved_by_user_id'));
    }

    // ------------------------------------------------------------ usar/anular

    public function test_usar_deja_dicho_a_que_documento_fue(): void
    {
        $numero = Correlativos::reservar($this->serieId);
        Correlativos::usar($numero->id, 'client_invoice', 77);

        $fila = DB::table('document_numbers')->where('id', $numero->id)->first();

        $this->assertSame('used', $fila->status);
        $this->assertSame('client_invoice', $fila->entity_type);
        $this->assertSame(77, (int) $fila->entity_id);
        $this->assertNotNull($fila->used_at);
    }

    public function test_un_numero_usado_ya_no_se_anula(): void
    {
        $numero = Correlativos::reservar($this->serieId);
        Correlativos::usar($numero->id, 'client_invoice', 77);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/finales/');

        Correlativos::anular($numero->id, 'Me arrepiento de haberla emitido.');
    }

    /**
     * Un hueco sin motivo no es un hueco defendible.
     *
     * Lo impide `ck_dn_anulado` en la base, y el servicio lo impide antes con
     * palabras: un `45000` en mitad de una pantalla no explica qué se esperaba.
     */
    public function test_anular_exige_un_motivo_que_explique(): void
    {
        $numero = Correlativos::reservar($this->serieId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/explicacion|explique/');

        Correlativos::anular($numero->id, 'error');
    }

    public function test_anular_queda_en_la_bitacora(): void
    {
        $numero = Correlativos::reservar($this->serieId);
        Correlativos::anular($numero->id, 'SUNAT rechazo el envio y se emitio con otra serie.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_number.voided',
            'entity_type' => 'document_number',
            'entity_id' => $numero->id,
        ]);
    }

    // ------------------------------------------------------------- la pantalla

    public function test_la_pantalla_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('series.index'))->assertStatus(403);
    }

    /**
     * Las series y el libro se ven **dentro de Integraciones** desde `9.17f`.
     *
     * Reportado por el negocio: *«tampoco veo dónde configuro los folios»*. No
     * los veía porque estaban en otra pantalla, y para emitir hacen falta a la
     * vez que la conexión y el certificado. La ruta vieja se queda y redirige:
     * los enlaces compartidos siguen funcionando.
     */
    public function test_el_admin_ve_las_series_y_el_libro(): void
    {
        $numero = Correlativos::reservar($this->serieId);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('series.index', ['serie' => $this->serieId]))
            ->assertRedirect(route('integraciones.index', [
                'p' => 'fel', 'serie' => $this->serieId,
            ]));

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index', ['p' => 'fel', 'serie' => $this->serieId]))
            ->assertOk()
            ->assertSee('F900')
            ->assertSee($numero->completo);
    }

    public function test_se_anula_desde_la_pantalla_con_su_motivo(): void
    {
        $numero = Correlativos::reservar($this->serieId);

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('series.anular', $numero->id), [
                'motivo' => 'La peticion fallo antes de emitir nada.',
                'serie' => $this->serieId,
            ])
            ->assertRedirect();

        $this->assertSame('voided', DB::table('document_numbers')
            ->where('id', $numero->id)->value('status'));
    }

    /** El choque de la serie por defecto se cuenta con palabras, no con un 1062. */
    public function test_una_segunda_serie_por_defecto_se_explica(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('series.serie'), [
                'legal_entity_id' => $this->sociedadId,
                'document_type_id' => $this->tipoId,
                'series' => 'F901',
                'next_number' => 1,
                'environment' => 'production',
                'is_active' => '1',
                'is_default' => '1',
            ])
            ->assertRedirect();

        $this->assertStringContainsString(
            'por defecto',
            (string) session('aviso'),
            'El choque de `uq_ds_default` tiene que contarse con palabras, no con un 1062.',
        );
    }

    // ----------------------------------------------------------------- avisos

    /**
     * Sin serie, rojo; con serie, no.
     *
     * Es la única configuración del sistema que **no trae valor de partida**: una
     * serie se registra ante la administración tributaria y una inventada
     * produciría comprobantes inválidos. Por eso avisa en vez de sembrarse, y por
     * eso no bloquea nada (`DEC-190`).
     */
    public function test_una_sociedad_sin_serie_sale_en_rojo(): void
    {
        $otra = $this->entidadLegal(['code' => 'SOC-SIN-SERIE']);

        $rojos = array_filter(Correlativos::avisos(), fn ($a): bool => $a->nivel === 'rojo');
        $textos = implode(' ', array_map(fn ($a): string => $a->texto, $rojos));

        $this->assertStringContainsString('SOC-SIN-SERIE', $textos);
        $this->assertGreaterThan(0, $otra);
    }

    /** Un número reservado y olvidado es un hueco en formación, y se dice. */
    public function test_un_numero_colgado_sale_en_rojo(): void
    {
        // Se crea YA VIEJA en vez de editarla: `reserved_at` es inmutable por
        // disparador y `tg_dn_no_delete` no deja borrar la de prueba —las dos
        // reglas hicieron falta para escribir esto, que es buena senal—.
        DB::statement(
            'INSERT INTO document_numbers (document_series_id, number, full_number, status,'
            .' reserved_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW() - INTERVAL 3 DAY, NOW(), NOW())',
            [$this->serieId, 500, 'F900-00000500', 'reserved'],
        );

        $textos = implode(' ', array_map(
            fn ($a): string => $a->texto,
            array_filter(Correlativos::avisos(), fn ($a): bool => $a->nivel === 'rojo'),
        ));

        $this->assertStringContainsString('sin documento', $textos);
    }

    /** El área está registrada en el panel de configuración de `9.17b`. */
    /**
     * El aviso sigue llegando al panel aunque el área ya no exista (`9.17f`).
     *
     * Es la mitad que no se puede olvidar al mover algo dentro de otra cosa: si
     * el «sin serie» se hubiera apagado, nadie lo habría notado hasta el día que
     * no se pudiera emitir.
     */
    public function test_el_aviso_sigue_llegando_al_panel_de_configuracion(): void
    {
        DB::table('document_series')->delete();

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('configuracion'))
            ->assertOk()
            ->assertSee('Integraciones');
    }
}
