<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\Terminos;
use App\Shared\Auth\Permisos;
use App\Shared\Database\Vigencia;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\TerminosBaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Volver a aceptar unos términos nuevos (iteración 9.19).
 *
 * ### Los tres escalones de `Q-46`
 *
 * > «Les llega un correo y tienen 15 días para aceptarlos […] si no lo
 * > aceptaron ingresa a una pantalla donde les exige aprobar para continuar; en
 * > caso contrario podrán ver todo en sólo lectura por 30 días.»
 *
 * Dentro del plazo se pasa con un aviso; pasado el plazo se puede **mirar** y no
 * tocar; pasada la ventana, sólo la pantalla de aceptar. Las tres están aquí, y
 * la del medio con las dos mitades: que un `GET` pase y que un `POST` no.
 *
 * ### Y la que evita el fallo que se ve con una persona de verdad
 *
 * Un creador activado **ayer** no puede aparecer bloqueado por una versión de
 * hace tres meses. Su plazo cuenta desde que se activó.
 */
final class ReaceptacionTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $creadorId;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Queue::fake();

        // El texto base lo publica su propio sembrador, y necesita un admin
        // antes: `PublicarTerminosCommand` resuelve un responsable y sin
        // usuarios no publica nada (lo dejaria como borrador).
        $this->usuarioCon('admin');
        $this->seed(TerminosBaseSeeder::class);

        // Un creador activo hace mucho, con cuenta de portal: el caso al que se
        // le aplica el muro.
        $this->creadorId = $this->creadorActivo(['activated_at' => '2026-01-01 09:00:00']);
        $this->usuario = $this->usuarioCon('creator');
        DB::table('users')->where('id', $this->usuario->id)->update(['user_type' => 'creator']);
        DB::table('creators')->where('id', $this->creadorId)
            ->update(['user_id' => $this->usuario->id]);
        $this->usuario = User::find($this->usuario->id);
    }

    // -------------------------------------------------------- los cuatro estados

    public function test_quien_acepto_la_vigente_esta_al_dia(): void
    {
        $this->aceptarLaVigente();

        $this->assertSame(Terminos::AL_DIA, Terminos::estadoDe($this->creadorId)['estado']);
    }

    /**
     * Publicada una versión de fondo, queda pendiente y con días por delante.
     *
     * Las fechas se calculan con `Vigencia` y no a mano: la puerta de vigencias
     * mira también `tests/`, y con razón — una prueba que calcula «quince días
     * después» por su cuenta puede desviarse del código que prueba.
     */
    public function test_una_version_de_fondo_deja_pendiente_a_quien_habia_aceptado(): void
    {
        $this->aceptarLaVigente();
        $desde = $this->publicarDeFondo(dias: 15, lectura: 30);

        $estado = Terminos::estadoDe($this->creadorId, Vigencia::masDias($desde, 5));

        $this->assertSame(Terminos::PENDIENTE, $estado['estado']);
        $this->assertSame(Vigencia::masDias($desde, 15), $estado['limite']);
        $this->assertSame(10, $estado['dias']);
    }

    public function test_pasado_el_plazo_queda_en_solo_lectura(): void
    {
        $desde = $this->publicarDeFondo(dias: 15, lectura: 30);

        $estado = Terminos::estadoDe($this->creadorId, Vigencia::masDias($desde, 20));

        $this->assertSame(Terminos::SOLO_LECTURA, $estado['estado']);
        $this->assertSame(Vigencia::masDias($desde, 45), $estado['finLectura']);
        $this->assertSame(25, $estado['dias']);
    }

    public function test_pasada_la_ventana_queda_bloqueado(): void
    {
        $desde = $this->publicarDeFondo(dias: 15, lectura: 30);

        $this->assertSame(Terminos::BLOQUEADO,
            Terminos::estadoDe($this->creadorId, Vigencia::masDias($desde, 60))['estado']);
    }

    /**
     * **La que evita el fallo con una persona de verdad.**
     *
     * Un creador activado ayer no puede estar bloqueado por una versión de hace
     * tres meses: su plazo cuenta desde que se activó.
     */
    public function test_el_creador_recien_activado_no_nace_bloqueado(): void
    {
        $desde = $this->publicarDeFondo(dias: 15, lectura: 30);

        // Se activa noventa dias despues de publicarse la version: con el reloj
        // de la version estaria bloqueado desde hace mes y medio.
        $activacion = Vigencia::masDias($desde, 90);
        DB::table('creators')->where('id', $this->creadorId)
            ->update(['activated_at' => $activacion.' 10:00:00']);

        $estado = Terminos::estadoDe($this->creadorId, Vigencia::masDias($activacion, 1));

        $this->assertSame(Terminos::PENDIENTE, $estado['estado']);
        $this->assertSame($activacion, $estado['desde']);
        $this->assertSame(14, $estado['dias']);
    }

    /**
     * `DEC-190`: sin términos vigentes, nadie está pendiente de nada.
     *
     * Se **cierra** la versión, no se despublica: `tg_terms_inmutable` impide
     * que una publicada vuelva a ser borrador, y con razón. Cerrarla sin
     * publicar la siguiente es el estado al que se llega de verdad.
     */
    public function test_sin_terminos_vigentes_nadie_esta_pendiente(): void
    {
        // Se cierra HOY y no «el dia antes»: la version sembrada empieza hoy, y
        // `ck_terms_versions_dates` no admite un cierre anterior a su propio
        // comienzo. Cerrarla hoy la saca de «vigente» --que es
        // `effective_to IS NULL`-- sin inventar una fecha imposible.
        DB::table('terms_versions')->whereNull('effective_to')->update([
            'effective_to' => now()->toDateString(),
        ]);

        $this->assertNull(Terminos::vigente(Terminos::codigo()));
        $this->assertSame(Terminos::AL_DIA, Terminos::estadoDe($this->creadorId)['estado']);
    }

    // ------------------------------------------------------------------ el muro

    /** Dentro del plazo se pasa: el muro no se levanta el primer día. */
    public function test_dentro_del_plazo_el_creador_sigue_entrando(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);

        $this->actingAs($this->usuario)->get(route('panel'))->assertOk();
    }

    /**
     * En sólo lectura, un `GET` pasa y un `POST` no.
     *
     * Es la mitad que se olvida: «sólo lectura» no es «una pantalla de sólo
     * lectura», es que no pueda escribir en **ninguna**.
     */
    public function test_en_solo_lectura_se_mira_pero_no_se_toca(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        // No se puede publicar en el pasado --la vigente empieza hoy y
        // `puedeRelevar()` lo impide-- asi que se avanza el reloj, que ademas
        // es lo que de verdad pasa: la version no se mueve, el tiempo si.
        $this->travel(20)->days();

        $this->actingAs($this->usuario)->get(route('panel'))->assertOk();

        // Cualquier ruta que cambie algo: la de aceptar los propios terminos es
        // la unica excepcion, y se comprueba en otra prueba.
        // Una ruta que CAMBIA algo. Se eligio `politica.store` a proposito:
        // el creador tampoco tiene `pricing.manage`, y el muro contesta ANTES
        // que el permiso --el middleware de grupo corre antes que el de ruta--,
        // asi que un 403 aqui significaria que el muro no se levanto.
        //
        // La primera version uso `contrasena.cambiar` y paso: esa ruta esta en
        // la lista de LIBRES del propio muro. Una asercion que elige justo la
        // excepcion mide la excepcion.
        $this->actingAs($this->usuario)->post(route('politica.store'))
            ->assertRedirect(route('terminos.mios'));
    }

    /** Y la contraseña sigue siendo una excepción: `T-23` manda sobre esto. */
    public function test_en_solo_lectura_todavia_se_puede_cambiar_la_contrasena(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(20)->days();

        // Llega al controlador y sale con errores de validacion --no se mando
        // nada--, que es la prueba de que el muro la dejo pasar. Se afirma sobre
        // los errores y no sobre el destino: `back()` sin `Referer` es la raiz,
        // y afirmar eso mediria el navegador y no el muro.
        $this->actingAs($this->usuario)->put(route('contrasena.cambiar'))
            ->assertSessionHasErrors();
    }

    public function test_pasada_la_ventana_todo_lleva_a_la_pantalla_de_aceptar(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(60)->days();

        $this->actingAs($this->usuario)->get(route('panel'))
            ->assertRedirect(route('terminos.mios'));
    }

    /** Y esa pantalla se abre siempre: nadie se queda sin salida. */
    public function test_la_pantalla_de_aceptar_se_abre_aunque_este_bloqueado(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(60)->days();

        $this->actingAs($this->usuario)->get(route('terminos.mios'))
            ->assertOk()
            ->assertSee('aceptar los términos para continuar');
    }

    /** Cerrar sesión también, que si no es una trampa. */
    public function test_bloqueado_puede_cerrar_sesion(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(60)->days();

        $this->actingAs($this->usuario)->post(route('salir'))->assertRedirect();
    }

    /** El equipo interno no se ve afectado por nada de esto. */
    public function test_el_equipo_interno_no_pasa_por_el_muro(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(60)->days();

        $this->actingAs($this->usuarioCon('admin'))->get(route('panel'))->assertOk();
    }

    // ------------------------------------------------------------------ aceptar

    public function test_aceptar_desbloquea_y_deja_constancia(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(60)->days();

        $this->actingAs($this->usuario)->post(route('terminos.aceptar'))
            ->assertRedirect(route('panel'));

        $this->assertSame(Terminos::AL_DIA, Terminos::estadoDe($this->creadorId)['estado']);
        $this->actingAs($this->usuario)->get(route('panel'))->assertOk();

        $fila = DB::table('terms_acceptances')
            ->where('subject_type', 'creator')->where('subject_id', $this->creadorId)
            ->orderByDesc('id')->first();

        $this->assertSame('portal', $fila->channel);
        $this->assertNull($fila->recorded_by_user_id);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'terms.accepted')->exists());
    }

    /** Pulsar dos veces no revienta: `uq_terms_acceptances_subject` lo impediría. */
    public function test_aceptar_dos_veces_no_es_un_error(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);

        $this->actingAs($this->usuario)->post(route('terminos.aceptar'));
        $this->actingAs($this->usuario)->post(route('terminos.aceptar'))->assertRedirect();

        $this->assertSame(1, DB::table('terms_acceptances')
            ->where('subject_id', $this->creadorId)->count());
    }

    // ------------------------------------------- lo que ve el equipo en el panel

    public function test_el_panel_de_configuracion_cuenta_a_los_que_faltan(): void
    {
        $this->publicarDeFondo(dias: 15, lectura: 30);
        $this->travel(60)->days();

        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertSee('hasta que acepten los términos vigentes');
    }

    // ------------------------------------------------------------------ apoyo

    private function aceptarLaVigente(): void
    {
        $vigente = Terminos::vigente(Terminos::codigo());
        $this->assertNotNull($vigente, 'el sembrador tiene que dejar una version publicada');

        Terminos::aceptar($this->creadorId, '127.0.0.1', 'phpunit');
    }

    /**
     * Publica una versión de fondo, que es la que obliga a todos a reaceptar.
     *
     * Se pasa por `Terminos::publicar()` y no por un `insert` a mano: lo que se
     * quiere probar es lo que hace el sistema, y un fixture que simula el
     * publicador puede desviarse de él — que es la lección de `H-16`.
     */
    private function publicarDeFondo(int $dias, int $lectura): string
    {
        $admin = $this->usuarioCon('admin');

        $uuid = Terminos::crearBorrador(
            Terminos::codigo(), '2030.'.mt_rand(100, 999), 'Términos nuevos',
            'Texto nuevo de los términos.', 'creator', (int) $admin->id,
        );

        // Desde MAÑANA: la que siembra `TerminosBaseSeeder` empieza hoy, y
        // `Vigencia::puedeRelevar()` no deja que la nueva entre el mismo día
        // — ese día tendría dos respuestas.
        $desde = Vigencia::elDiaDespuesDe(now()->toDateString());

        Terminos::publicar($uuid, 'fondo', $desde, (int) $admin->id, $dias, $lectura);

        return $desde;
    }
}
