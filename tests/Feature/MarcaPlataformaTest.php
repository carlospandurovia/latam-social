<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La identidad de la plataforma (iteración 9.17).
 *
 * ### Qué fija esta prueba
 *
 * Que «LATAM Social» ya no está escrito en ninguna plantilla. Lo que se
 * comprueba no es que el servicio devuelva un nombre —eso sería probar un
 * `SELECT`—, sino que **la pantalla enseña el nombre que hay en la base**: es la
 * única forma de que un `@include` olvidado o una plantilla nueva con el nombre
 * escrito a mano salgan en rojo.
 *
 * ### Y que una configuración vacía no bloquea nada (`DEC-190`)
 *
 * Sin marca, sin logotipo y sin favicon, el panel entra igual y enseña los
 * valores de partida. Lo que aparece es un aviso con prioridad, no una puerta.
 */
final class MarcaPlataformaTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();
    }

    /**
     * Deja el sistema **sin ninguna marca utilizable**.
     *
     * No se borra la fila: `legal_entities` apunta a ella y
     * `legal_entity_countries` no admite borrado —dice qué sociedad facturó qué
     * país y desde cuándo—. Se desactiva, que además es el estado al que se
     * puede llegar de verdad desde la aplicación. Los dos campos van en el
     * mismo `UPDATE` porque `ck_pb_defecto_activa` prohíbe el paso intermedio.
     */
    private function sinMarcaUtilizable(): void
    {
        DB::table('platform_brands')->update(['is_default' => 0, 'is_active' => 0]);
        Marca::olvidar();
    }

    // --------------------------------------------------- el nombre sale de la base

    /**
     * **La que más importa.** Cambiar el nombre en la base cambia la pantalla.
     *
     * Si alguien vuelve a escribir «LATAM Social» en una plantilla, esta prueba
     * lo ve: el panel enseñaría el nombre viejo teniendo otro guardado.
     */
    public function test_el_panel_ensena_el_nombre_que_hay_en_la_base(): void
    {
        DB::table('platform_brands')->where('is_default', 1)->update(['name' => 'Creators MX']);
        Marca::olvidar();

        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Creators MX');
        $respuesta->assertDontSee('LATAM Social');
    }

    /** Y la pantalla de acceso también, que la ve quien todavía no ha entrado. */
    public function test_la_pantalla_de_acceso_ensena_el_nombre_y_el_pie_legal_guardados(): void
    {
        DB::table('platform_brands')->where('is_default', 1)->update([
            'name' => 'Creators MX',
            'legal_footer' => 'Otra Sociedad S.A. de C.V. · RFC XAXX010101000',
        ]);
        Marca::olvidar();

        $respuesta = $this->get(route('acceso'));

        $respuesta->assertOk();
        $respuesta->assertSee('Creators MX');
        $respuesta->assertSee('XAXX010101000');
    }

    /** El color guardado llega a la hoja de estilo de la página. */
    public function test_el_color_guardado_llega_a_la_hoja_de_estilo(): void
    {
        DB::table('platform_brands')->where('is_default', 1)->update([
            'primary_color' => '#FF0066',
            'sidebar_color' => '#101010',
        ]);
        Marca::olvidar();

        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertSee('--marca: #FF0066', escape: false);
        $respuesta->assertSee('--barra: #101010', escape: false);
    }

    // ----------------------------------------------- sin configurar no se bloquea

    /**
     * `DEC-190`: sin ninguna marca, el panel entra igual con los valores de partida.
     *
     * Es la regla entera en una prueba: una configuración que falta produce un
     * valor de partida y un aviso, nunca una puerta cerrada.
     */
    public function test_sin_ninguna_marca_el_panel_sigue_entrando(): void
    {
        $this->sinMarcaUtilizable();

        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('LATAM Social');
    }

    /** Y lo dice: un aviso rojo, no un error. */
    public function test_sin_marca_el_aviso_es_rojo_y_no_un_bloqueo(): void
    {
        $this->sinMarcaUtilizable();

        $avisos = Marca::avisos();

        $this->assertCount(1, $avisos);
        $this->assertSame('rojo', $avisos[0]['nivel']);
    }

    /** Sin logotipo y sin correo de soporte: dos rojos, y la pantalla abre. */
    public function test_sin_logotipo_ni_correo_de_soporte_hay_dos_avisos_rojos(): void
    {
        $niveles = array_column(Marca::avisos(), 'nivel');

        $this->assertSame(2, count(array_filter($niveles, static fn ($n) => $n === 'rojo')));

        $this->actingAs($this->usuarioCon('admin'))->get(route('marca.index'))->assertOk();
    }

    // ------------------------------------------------------------------ guardar

    public function test_guardar_cambia_la_marca_y_queda_en_la_bitacora(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('admin'))->put(route('marca.update'), [
            'name' => 'Creators MX',
            'support_email' => 'soporte@creators.mx',
            'website' => 'https://creators.mx',
            'primary_color' => '#FF0066',
        ]);

        $respuesta->assertRedirect(route('marca.index'));

        $this->assertSame('Creators MX',
            DB::table('platform_brands')->where('is_default', 1)->value('name'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'platform_brand.updated')->exists());
    }

    /** Un campo que se deja en blanco se guarda como NULL, no como cadena vacía. */
    public function test_un_campo_vaciado_se_guarda_como_nulo_y_no_como_cadena_vacia(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->put(route('marca.update'), [
            'name' => 'LATAM Social',
            'website' => '',
            'support_email' => '',
        ]);

        $fila = DB::table('platform_brands')->where('is_default', 1)->first();

        // `ck_pb_correo` y `ck_pb_web` admiten NULL --«no configurado»-- y
        // rechazan una cadena vacia, que no es lo mismo y no significa nada.
        $this->assertNull($fila->website);
        $this->assertNull($fila->support_email);
    }

    public function test_una_web_sin_esquema_no_pasa(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('marca.update'), ['name' => 'X', 'website' => 'creators.mx'])
            ->assertSessionHasErrors('website');
    }

    /**
     * La tipografía acaba en una URL y en una hoja de estilo.
     *
     * Un nombre con comillas o con `;` se sale de la regla CSS y escribe estilo
     * ajeno en TODAS las pantallas. Es una inyección, no una errata.
     */
    public function test_una_tipografia_con_comillas_no_pasa(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('marca.update'), [
                'name' => 'X',
                'font_family' => "Arial'; } body { display:none } .x{",
            ])
            ->assertSessionHasErrors('font_family');
    }

    /**
     * Un color sin poner se cae al de partida, no deja la hoja de estilo a medias.
     *
     * La otra mitad de esta regla —un color **corrupto** en la base tampoco se
     * escribe— no se puede probar desde aquí, y conviene decir por qué antes de
     * que alguien lo intente: `ck_pb_color` la impone en el motor, así que ni
     * siquiera un `UPDATE` en crudo consigue meter `'rojo'` en esa columna.
     * Escribir esa prueba obligaría a quitar la restricción primero, y entonces
     * mediría un sistema que no es el que se despliega. La comprobación de
     * `Marca::color()` es la segunda cerradura de una puerta que ya está
     * cerrada: existe para el día que el valor llegue por una vía que no pasa
     * por el motor —una restauración de copia, una carga masiva—.
     */
    public function test_un_color_sin_poner_se_cae_al_de_partida(): void
    {
        DB::table('platform_brands')->where('is_default', 1)
            ->update(['primary_color' => null, 'sidebar_color' => null]);
        Marca::olvidar();

        $this->assertSame('#7C3AED', Marca::datos()['color']);
        $this->assertSame('#070A2B', Marca::datos()['barra']);
    }

    /**
     * La pantalla de «sin permiso» también lleva la marca, y también tiene que pintarse.
     *
     * Está aquí porque **se rompió**: el compositor se registró para
     * `errors.403` y el manejador de excepciones de Laravel resuelve esa
     * pantalla como `errors::403`. Resultado: `$marca` no existía y un 403
     * salía como 500. Un error de permisos que se presenta como avería del
     * sistema es de los peores: manda a alguien a mirar los registros en vez
     * de a pedir el permiso que le falta.
     */
    public function test_la_pantalla_de_sin_permiso_se_pinta_con_la_marca(): void
    {
        DB::table('platform_brands')->where('is_default', 1)->update(['name' => 'Creators MX']);
        Marca::olvidar();

        $respuesta = $this->actingAs($this->usuarioCon('campaign_manager'))->get(route('marca.index'));

        $respuesta->assertForbidden();
        $respuesta->assertSee('Creators MX');
    }

    // ------------------------------------------------------------------ archivos

    public function test_subir_un_logotipo_lo_deja_servido_por_la_puerta_publica(): void
    {
        Storage::fake('local');

        $this->actingAs($this->usuarioCon('admin'))->put(route('marca.update'), [
            'name' => 'LATAM Social',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertRedirect(route('marca.index'));

        $fila = DB::table('platform_brands')->where('is_default', 1)->first();
        $this->assertNotNull($fila->logo_file_id);

        $archivo = DB::table('files')->where('id', $fila->logo_file_id)->first();
        $this->assertSame(Marca::PROPOSITO, $archivo->purpose);

        // Sin sesion: es la pantalla de acceso la que lo necesita.
        $this->get(route('marca.logo'))->assertOk();
    }

    /** Sin favicon propio se sirve el logotipo: la pestaña no se queda sin icono. */
    public function test_sin_favicon_propio_se_sirve_el_logotipo(): void
    {
        Storage::fake('local');

        $this->actingAs($this->usuarioCon('admin'))->put(route('marca.update'), [
            'name' => 'LATAM Social',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        Marca::olvidar();
        $this->assertNull(DB::table('platform_brands')->where('is_default', 1)->value('favicon_file_id'));
        $this->get(route('marca.favicon'))->assertOk();
    }

    /** Y sin ninguno de los dos, un 404 limpio en vez de un cuerpo vacío con 200. */
    public function test_sin_logotipo_la_puerta_publica_da_404(): void
    {
        $this->get(route('marca.logo'))->assertNotFound();
    }

    /**
     * La fila existe y el archivo no: la «evidencia fantasma» de 9.15.
     *
     * Se dice con un 404, no se sirve un cuerpo vacío con un 200 que el
     * navegador pintaría como una imagen rota.
     */
    public function test_si_el_archivo_no_esta_en_el_disco_da_404(): void
    {
        Storage::fake('local');

        $archivoId = DB::table('files')->insertGetId([
            'uuid' => (string) Str::uuid(), 'disk' => 'local', 'path' => 'no/existe.png',
            'original_name' => 'logo.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'x'), 'visibility' => 'private',
            'purpose' => Marca::PROPOSITO, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('platform_brands')->where('is_default', 1)->update(['logo_file_id' => $archivoId]);
        Marca::olvidar();

        $this->get(route('marca.logo'))->assertNotFound();
    }

    /** La puerta pública no acepta identificador: por ahí no se pide otra cosa. */
    public function test_la_puerta_publica_no_acepta_un_identificador(): void
    {
        $ruta = route('marca.logo');

        $this->assertStringEndsWith('/marca/logo', $ruta);
        $this->get($ruta.'/1')->assertNotFound();
    }

    // -------------------------------------------------------------------- permiso

    public function test_sin_brand_manage_no_se_entra_ni_se_guarda(): void
    {
        $usuario = $this->usuarioCon('campaign_manager');

        $this->actingAs($usuario)->get(route('marca.index'))->assertForbidden();
        $this->actingAs($usuario)->put(route('marca.update'), ['name' => 'X'])->assertForbidden();
    }

    /**
     * `brand.manage` es PROPIO: `legal_entity.manage` no lo abre.
     *
     * Hoy los dos los tiene sólo `admin`, así que la prueba se hace con un
     * usuario al que se le da uno y no el otro. Sin esto, el día que
     * `legal_entity.manage` se le dé a alguien de operaciones, esa persona
     * podría cambiar lo que ve todo el mundo y nadie se enteraría.
     */
    public function test_legal_entity_manage_no_abre_la_marca(): void
    {
        $usuario = $this->usuarioCon(null);
        $rolId = DB::table('roles')->insertGetId([
            'code' => 'solo_sociedades', 'name' => 'Solo sociedades',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permission_role')->insert([
            'role_id' => $rolId,
            'permission_id' => DB::table('permissions')->where('code', 'legal_entity.manage')->value('id'),
        ]);
        DB::table('role_user')->insert([
            'user_id' => $usuario->id, 'role_id' => $rolId, 'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        $this->actingAs($usuario)->get(route('entidades.index'))->assertOk();
        $this->actingAs($usuario)->get(route('marca.index'))->assertForbidden();
    }

    // ------------------------------------------------------- el codigo no se toca

    /**
     * `tg_pb_code` lo impide en el motor, no sólo en el formulario.
     *
     * Si el código cambia, el siguiente sembrado no encuentra la marca y crea
     * otra: el sistema amanece con dos y las pantallas siguen enseñando la
     * vieja mientras alguien edita la nueva.
     */
    public function test_el_codigo_de_la_marca_no_se_puede_cambiar(): void
    {
        $this->expectExceptionMessageMatches('/no se cambia/');

        DB::table('platform_brands')->where('is_default', 1)->update(['code' => 'otra']);
    }

    /** Y una segunda marca por defecto tampoco: `uq_pb_default`. */
    public function test_no_puede_haber_dos_marcas_por_defecto(): void
    {
        $this->expectExceptionMessageMatches('/uq_pb_default|Duplicate/');

        DB::table('platform_brands')->insert([
            'uuid' => (string) Str::uuid(), 'code' => 'otra', 'name' => 'Otra',
            'is_active' => true, 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
