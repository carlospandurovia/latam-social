<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Autorización por permiso (iteración 3.1).
 *
 * Se siembra `CimientosSeeder` de verdad en lugar de insertar tres filas a mano:
 * lo que hay que comprobar no es que el middleware sepa comparar cadenas, sino
 * que **la matriz real de roles y permisos concede lo que se pretendía**. Un
 * `content_reviewer` que pueda ver cuentas bancarias es un fallo de datos, no de
 * código, y solo aparece si se prueban los datos reales.
 */
final class PermisosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        // La caché de permisos es estática: sin esto, la segunda prueba vería
        // los permisos de la primera y todo pasaría por el motivo equivocado.
        Permisos::olvidar();
    }

    // ------------------------------------------------------------ el middleware

    public function test_sin_rol_no_se_entra_a_ninguna_pantalla_de_negocio(): void
    {
        $usuario = $this->usuarioCon(null);

        $this->actingAs($usuario)->get('/creadores')->assertForbidden();
        $this->actingAs($usuario)->get('/catalogos/currencies')->assertForbidden();
    }

    /** Rol externo: existe, tiene usuario, y no toca nada interno. */
    public function test_un_rol_externo_no_alcanza_el_back_office(): void
    {
        $usuario = $this->usuarioCon('client_user');

        $this->actingAs($usuario)->get('/creadores')->assertForbidden();
        $this->actingAs($usuario)->get('/catalogos/currencies')->assertForbidden();
    }

    public function test_con_el_permiso_correcto_se_entra(): void
    {
        $usuario = $this->usuarioCon('content_reviewer');

        $this->actingAs($usuario)->get('/creadores')->assertOk();
        $this->actingAs($usuario)->get('/catalogos/currencies')->assertOk();
    }

    public function test_el_administrador_llega_a_todo(): void
    {
        $usuario = $this->usuarioCon('admin');

        $this->actingAs($usuario)->get('/creadores')->assertOk();
        $this->actingAs($usuario)->get('/catalogos/currencies')->assertOk();
    }

    /** El panel es la portada de cualquier usuario interno: no exige permiso. */
    public function test_el_panel_no_exige_permiso_pero_si_sesion(): void
    {
        $this->get('/panel')->assertRedirect('/entrar');
        $this->actingAs($this->usuarioCon(null))->get('/panel')->assertOk();
    }

    // ------------------------------------------------------- la matriz de datos

    public function test_solo_finanzas_y_administracion_ven_datos_fiscales(): void
    {
        $finanzas = $this->usuarioCon('finance');
        $revisor = $this->usuarioCon('content_reviewer');
        $gestor = $this->usuarioCon('campaign_manager');

        $this->assertTrue(Permisos::tiene((int) $finanzas->id, 'creator.view_sensitive'));
        $this->assertFalse(Permisos::tiene((int) $revisor->id, 'creator.view_sensitive'));
        $this->assertFalse(Permisos::tiene((int) $gestor->id, 'creator.view_sensitive'));
    }

    /** BR-FIN-007: el margen interno no es para cualquiera. */
    public function test_el_margen_interno_no_lo_ve_el_revisor_de_contenido(): void
    {
        $revisor = $this->usuarioCon('content_reviewer');

        $this->assertFalse(Permisos::tiene((int) $revisor->id, 'campaign.view_margin'));
    }

    /** La bitácora y las credenciales de integración son solo de administración. */
    public function test_auditoria_e_integraciones_son_solo_de_administracion(): void
    {
        $admin = $this->usuarioCon('admin');
        $finanzas = $this->usuarioCon('finance');

        $this->assertTrue(Permisos::tiene((int) $admin->id, 'audit.view'));
        $this->assertTrue(Permisos::tiene((int) $admin->id, 'integration.manage'));
        $this->assertFalse(Permisos::tiene((int) $finanzas->id, 'audit.view'));
        $this->assertFalse(Permisos::tiene((int) $finanzas->id, 'integration.manage'));
    }

    /**
     * Si se añade un permiso y no se concede a nadie, no existe para el sistema.
     * Es un fallo silencioso: la pantalla devuelve 403 a todo el mundo y nadie
     * sabe por qué.
     */
    public function test_todo_permiso_esta_concedido_al_menos_a_un_rol(): void
    {
        $huerfanos = DB::table('permissions as p')
            ->leftJoin('permission_role as pr', 'pr.permission_id', '=', 'p.id')
            ->whereNull('pr.permission_id')
            ->pluck('p.code')
            ->all();

        $this->assertSame([], $huerfanos, 'Permisos que ningún rol tiene: '.implode(', ', $huerfanos));
    }

    /** Sin atajos: `admin` tiene los permisos como filas, no como excepción. */
    public function test_el_administrador_tiene_sus_permisos_como_datos(): void
    {
        $total = DB::table('permissions')->count();
        $delAdmin = DB::table('permission_role as pr')
            ->join('roles as r', 'r.id', '=', 'pr.role_id')
            ->where('r.code', 'admin')
            ->count();

        $this->assertSame($total, $delAdmin);
    }
}
