<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Alertas;
use App\Modules\Core\Services\Marca;
use App\Shared\Auth\Permisos;
use Carbon\CarbonImmutable;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-3`: «Requiere atención».
 *
 * ### Qué fija, y por qué cada cosa
 *
 * 1. **Nada pendiente se dice con palabras**, no con una sección vacía.
 * 2. **Si no puedes arreglarlo, no lo ves.** Un aviso que lleva a un 403 es
 *    peor que no tenerlo.
 * 3. **Las alertas NO se recortan por periodo** (`DEC-311`). Es la prueba menos
 *    obvia y la que más protege: con recorte, la factura vencida hace tres meses
 *    desaparecería del panel **en silencio** y parecería que hay menos trabajo.
 * 4. **Cada alerta lleva a una ruta que existe.** Un `route()` mal escrito
 *    revienta la portada entera, y la portada es la primera pantalla del día.
 */
final class AlertasTest extends TestCase
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

    // ------------------------------------------------------------ estado vacío

    public function test_sin_nada_pendiente_lo_dice_en_vez_de_dejar_un_hueco(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('No hay nada pendiente');
    }

    // -------------------------------------------------------------- se cuentan

    public function test_una_solicitud_sin_revisar_aparece_con_su_antiguedad(): void
    {
        $this->solicitudDeCreador(CarbonImmutable::now()->subDays(4));

        $alertas = Alertas::para($this->usuarioCon('admin'));
        $solicitudes = $this->porClave($alertas, 'solicitudes_creador');

        self::assertNotNull($solicitudes, 'la alerta tiene que estar');
        self::assertSame(1, $solicitudes['cantidad']);
        self::assertSame(4, $solicitudes['antiguedad']);
        self::assertSame(Alertas::AMBAR, $solicitudes['nivel']);
    }

    /** La antigüedad la marca el MÁS VIEJO, no el último que llegó. */
    public function test_la_antiguedad_es_la_del_caso_mas_viejo(): void
    {
        $this->solicitudDeCreador(CarbonImmutable::now()->subDays(30));
        $this->solicitudDeCreador(CarbonImmutable::now()->subDay());

        $solicitudes = $this->porClave(
            Alertas::para($this->usuarioCon('admin')),
            'solicitudes_creador',
        );

        self::assertSame(2, $solicitudes['cantidad']);
        self::assertSame(30, $solicitudes['antiguedad']);
    }

    /** Lo ya resuelto no molesta: una alerta en cero no sale. */
    public function test_una_solicitud_ya_revisada_no_genera_alerta(): void
    {
        $id = $this->solicitudDeCreador(CarbonImmutable::now()->subDays(3));
        DB::table('creator_applications')->where('id', $id)->update(['status' => 'approved']);

        self::assertNull($this->porClave(
            Alertas::para($this->usuarioCon('admin')),
            'solicitudes_creador',
        ));
    }

    // ---------------------------------------------------------- los permisos

    /**
     * Quien no puede resolverlo no lo ve.
     *
     * `content_reviewer` **no** tiene `creator.approve`, así que la cola de
     * solicitudes no es asunto suyo. Sin esto, el panel reuniría en una pantalla
     * lo que el resto del sistema reparte por permisos, que es la fuga que
     * `BR-SEC-001` prohíbe.
     *
     * La primera versión usaba `campaign_manager` **y ese rol sí lo tiene**: la
     * prueba fallaba por una premisa falsa, no por un fallo del código. Por eso
     * el permiso se comprueba aquí en vez de darse por sabido — si mañana
     * alguien le concede `creator.approve` a este rol, el mensaje dirá qué pasó
     * en lugar de acusar a `Alertas`.
     */
    public function test_sin_el_permiso_que_la_resuelve_la_alerta_no_se_ve(): void
    {
        $this->solicitudDeCreador(CarbonImmutable::now()->subDays(2));

        $admin = $this->usuarioCon('admin');
        $revisor = $this->usuarioCon('content_reviewer');

        self::assertTrue($admin->can('creator.approve'), 'la premisa: el admin puede aprobar creadores');
        self::assertFalse($revisor->can('creator.approve'), 'la premisa: el revisor de contenido NO puede');

        self::assertNotNull($this->porClave(Alertas::para($admin), 'solicitudes_creador'));
        self::assertNull($this->porClave(Alertas::para($revisor), 'solicitudes_creador'));
    }

    // -------------------------------------------------- no dependen del periodo

    /**
     * **La prueba de `DEC-311`.**
     *
     * Con el periodo puesto en «hoy», una solicitud de hace doscientos días
     * sigue apareciendo. Si algún día alguien decide «que los filtros afecten a
     * todo», esta prueba se pone roja y obliga a leer por qué no.
     */
    public function test_el_periodo_elegido_no_esconde_lo_atrasado(): void
    {
        $this->solicitudDeCreador(CarbonImmutable::now()->subDays(200));

        $respuesta = $this->actingAs($this->usuarioCon('admin'))
            ->get(route('panel', ['periodo' => 'hoy']));

        $respuesta->assertOk();
        $respuesta->assertSee('Solicitudes de creador sin revisar');
        $respuesta->assertSee('200');
    }

    // ------------------------------------------------------------- el orden

    /**
     * Rojas primero; dentro del mismo nivel, las más numerosas antes.
     *
     * Sobre la función pura y no sobre la base: montar a la vez una alerta roja
     * y una ámbar con datos de verdad exige fabricar creador activo, medio de
     * pago verificado, lote y entregable, y una prueba con cinco pasos de
     * preparación acaba comprobando la preparación.
     */
    public function test_las_rojas_van_primero_y_luego_las_mas_numerosas(): void
    {
        $ordenadas = Alertas::ordenar([
            ['clave' => 'ambar_pocas', 'nivel' => Alertas::AMBAR, 'cantidad' => 2],
            ['clave' => 'roja_pocas', 'nivel' => Alertas::ROJO, 'cantidad' => 1],
            ['clave' => 'ambar_muchas', 'nivel' => Alertas::AMBAR, 'cantidad' => 90],
            ['clave' => 'roja_muchas', 'nivel' => Alertas::ROJO, 'cantidad' => 5],
        ]);

        self::assertSame(
            ['roja_muchas', 'roja_pocas', 'ambar_muchas', 'ambar_pocas'],
            array_column($ordenadas, 'clave'),
        );
    }

    // --------------------------------------------------- las rutas existen

    /**
     * Cada alerta lleva a una ruta REAL.
     *
     * Un nombre de ruta mal escrito no falla al escribirlo: falla al pintar la
     * portada, con un `RouteNotFoundException` que deja sin primera pantalla a
     * todo el equipo. Es exactamente lo que `verificar-pantallas.py` caza en las
     * plantillas, y estas rutas viven en PHP, donde no llega.
     */
    public function test_todas_las_alertas_apuntan_a_una_ruta_que_existe(): void
    {
        $this->solicitudDeCreador(CarbonImmutable::now());
        $this->prospectoSinAtender();
        $this->correoFallido();

        $alertas = Alertas::para($this->usuarioCon('admin'));

        self::assertNotEmpty($alertas, 'la premisa: tiene que haber alertas que comprobar');

        foreach ($alertas as $alerta) {
            self::assertNotNull(
                Route::getRoutes()->getByName($alerta['ruta']),
                "la alerta «{$alerta['clave']}» apunta a la ruta inexistente «{$alerta['ruta']}»",
            );
        }
    }

    public function test_un_prospecto_y_un_correo_fallido_tambien_se_cuentan(): void
    {
        $this->prospectoSinAtender();
        $this->correoFallido();

        $alertas = Alertas::para($this->usuarioCon('admin'));

        self::assertSame(1, $this->porClave($alertas, 'prospectos_sin_atender')['cantidad']);
        self::assertSame(1, $this->porClave($alertas, 'correos_fallidos')['cantidad']);
    }

    // ------------------------------------------------------------- ayudantes

    /** @param list<array<string, mixed>> $alertas */
    private function porClave(array $alertas, string $clave): ?array
    {
        foreach ($alertas as $alerta) {
            if ($alerta['clave'] === $clave) {
                return $alerta;
            }
        }

        return null;
    }

    private function solicitudDeCreador(CarbonImmutable $cuando): int
    {
        return (int) DB::table('creator_applications')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ana Postulante',
            'email' => 'ana+'.Str::random(6).'@ejemplo.pe',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'source' => 'landing',
            'status' => 'submitted',
            'submitted_at' => $cuando->toDateTimeString(),
            'created_at' => $cuando->toDateTimeString(),
            'updated_at' => $cuando->toDateTimeString(),
        ]);
    }

    private function prospectoSinAtender(): int
    {
        return (int) DB::table('client_leads')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_name' => 'Marca Nueva',
            'contact_name' => 'Luis Contacto',
            'email' => 'luis+'.Str::random(6).'@marca.pe',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'source' => 'landing',
            'status' => 'new',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function correoFallido(): int
    {
        return (int) DB::table('email_log')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'template_code' => 'creator.welcome',
            'template_version' => '1.0',
            'template_locale' => 'es',
            'locale_requested' => 'es',
            'to_email' => 'nadie@ejemplo.pe',
            'subject' => 'Bienvenida',
            'body_sha256' => str_repeat('a', 64),
            'status' => 'failed',
            'attempts' => 3,
            // `ck_el_failed`: un correo fallido tiene que decir CUÁNDO y POR QUÉ.
            // Sin las dos cosas, «falló» es una etiqueta sin nada detrás.
            'failed_at' => now(),
            'last_error' => 'Connection refused',
            'queued_at' => now(),
        ]);
    }
}
