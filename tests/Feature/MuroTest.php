<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El muro: qué NO puede alcanzar alguien de fuera (auditoría de seguridad, 9.14).
 *
 * ### Por qué una matriz y no tres pruebas
 *
 * Las afirmaciones de seguridad de este proyecto viven repartidas en
 * comentarios: el motivo interno de una retención no cruza (`DEC-172`), un
 * correo de pago no lleva número de cuenta (`DEC-179`), el margen es de
 * dirección (`DEC-181`). Cada una tiene su prueba, y **todas comprueban lo que
 * alguien recordó mirar**.
 *
 * El fallo que de verdad pasa es otro: una pantalla nueva sin su `permiso:`. Ya
 * ocurrió —en `5.9`, la portada enseñaba los totales internos a cualquier
 * autenticado— y no lo habría cazado ninguna prueba dirigida, porque nadie
 * escribe una prueba para la pantalla en la que no está pensando.
 *
 * Así que esta clase recorre **todas las rutas con nombre** y, para cada rol de
 * fuera, exige un 403 en todas menos en las suyas. Una ruta nueva entra sola en
 * la prueba: no hay que acordarse de nada.
 *
 * ### Qué NO cubre
 *
 * Que una pantalla a la que se tiene derecho imprima de más. Eso es la frontera
 * de `Ledger::misIngresos()` —enumerar columnas— y se prueba donde vive.
 */
final class MuroTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    /**
     * Lo único que un creador puede abrir. `BR-SEC-003`.
     *
     * Se escribe a mano y en positivo: una lista de lo permitido crece sólo
     * cuando alguien la toca a propósito, y una de lo prohibido se queda corta
     * cada vez que nace una pantalla.
     *
     * @var list<string>
     */
    private const CREADOR_PUEDE = [
        'entregas.mias', 'entregas.ver', 'entregas.entregar', 'entregas.publicar',
        'ingresos.mios',
    ];

    /** El portal del cliente no existe todavía: entra por enlace firmado. */
    private const CLIENTE_PUEDE = [];

    /**
     * Rutas sin `permiso:` — abiertas a propósito, o de todo autenticado.
     *
     * Se quedan fuera de la matriz porque su defensa es otra: una firma en el
     * enlace, un `throttle`, o simplemente estar dentro. `verificar-muro.py`
     * comprueba que esta lista y la del repositorio digan lo mismo.
     *
     * @var list<string>
     */
    private const ABIERTAS = [
        'acceso', 'entrar', 'salir', 'panel', 'contrasena', 'contrasena.cambiar',
        'recuperar', 'recuperar.enviar', 'recuperar.usar', 'recuperar.formulario', 'recuperar.fijar',
        'invitacion.ver', 'invitacion.oferta', 'invitacion.aceptar', 'invitacion.rechazar',
        'invitacion.preguntar', 'invitacion.gracias', 'invitacion.caducada',
        'aprobacion.ver', 'aprobacion.pieza', 'aprobacion.responder',
        'aprobacion.gracias', 'aprobacion.caducada',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    /** **La que importa.** Un creador no alcanza ninguna pantalla interna. */
    public function test_un_creador_no_alcanza_nada_que_no_sea_suyo(): void
    {
        $this->muro('creator', self::CREADOR_PUEDE);
    }

    /** Y un usuario de cliente, todavía menos: su rol nace vacío. */
    public function test_un_usuario_de_cliente_no_alcanza_nada(): void
    {
        $this->muro('client_user', self::CLIENTE_PUEDE);
    }

    /**
     * Y alguien autenticado SIN rol tampoco.
     *
     * Es el caso de `5.9`: una cuenta recién creada, todavía sin rol, que
     * navegaba por URL. Un 403 aquí es la diferencia entre una pantalla en
     * blanco y los totales internos de la empresa.
     */
    public function test_un_usuario_sin_rol_no_alcanza_nada(): void
    {
        $this->muro(null, []);
    }

    /**
     * Y lo de dentro de la lista **sí** se abre.
     *
     * Sin esto, una errata en `CREADOR_PUEDE` no se notaría por el lado que
     * importa: la ruta mal escrita volvería al barrido y el muro seguiría verde
     * mientras el creador se queda fuera de su propio portal.
     */
    public function test_el_creador_si_entra_a_lo_suyo(): void
    {
        $usuario = $this->comoRol('creator');

        $this->actingAs($usuario)->get(route('entregas.mias'))->assertOk();
        $this->actingAs($usuario)->get(route('ingresos.mios'))->assertOk();
    }

    /**
     * La lista de rutas abiertas del código y la del repositorio, iguales.
     *
     * Dos listas de lo mismo es como se pierde una (`SUITES`, 3.12). Aquí no se
     * pueden fundir —una la lee PHP y otra Python, sin Laravel— así que se
     * comprueba que digan lo mismo.
     */
    public function test_la_lista_de_rutas_abiertas_no_se_ha_quedado_vieja(): void
    {
        $abiertas = [];

        foreach (Route::getRoutes() as $ruta) {
            $nombre = $ruta->getName();
            if ($nombre === null) {
                continue;
            }
            if (!$this->exigePermiso($ruta->gatherMiddleware())) {
                $abiertas[] = $nombre;
            }
        }

        sort($abiertas);
        $declaradas = self::ABIERTAS;
        sort($declaradas);

        $this->assertSame($declaradas, $abiertas,
            'Hay una ruta sin `permiso:` que no esta en la lista de abiertas, '
            .'o una de la lista que ya no existe.');
    }

    // ------------------------------------------------------- el aparato

    /**
     * Pide TODAS las rutas con nombre como `$rol` y exige 403 en las que no
     * están en `$permitidas`.
     *
     * @param list<string> $permitidas
     */
    private function muro(?string $rol, array $permitidas): void
    {
        $usuario = $this->comoRol($rol);
        $mirado = 0;
        $negadas = 0;

        foreach (Route::getRoutes() as $ruta) {
            $nombre = $ruta->getName();

            if ($nombre === null
                || in_array($nombre, self::ABIERTAS, true)
                || in_array($nombre, $permitidas, true)) {
                continue;
            }
            if (!$this->exigePermiso($ruta->gatherMiddleware())) {
                continue;
            }

            // El verbo REAL de la ruta. Con `post` a secas, un `PUT` contesta
            // 405 --que no es 403 y tampoco es una puerta abierta-- y la prueba
            // se para midiendo otra cosa.
            $verbo = mb_strtolower((string) collect($ruta->methods())
                ->first(static fn (string $m): bool => $m !== 'HEAD'));
            $url = $this->url($ruta);

            $respuesta = $this->actingAs($usuario)->{$verbo}($url);
            $codigo = $respuesta->getStatusCode();
            $mirado++;

            // **403 o 404, nunca otra cosa.**
            //
            // El 404 se admite y hay que decir por que, o se convierte en el
            // escondite de esta prueba. `SubstituteBindings` corre ANTES del
            // middleware de ruta, asi que una ruta con enlace de modelo
            // contesta 404 a un id que no existe --y aqui los ids son
            // inventados-- sin llegar nunca a `permiso:`. No es una puerta
            // abierta: con un id de verdad, el siguiente en la fila es el
            // permiso. Lo que NO puede pasar nunca es un 2xx ni una redireccion
            // a contenido: eso si seria haber entrado.
            $this->assertContains($codigo, [403, 404],
                sprintf('La ruta %s (%s) devolvio %d a un %s.',
                    $nombre, $url, $codigo, $rol ?? 'usuario sin rol'));

            if ($codigo === 403) {
                $negadas++;
            }
        }

        // Contar que no hay problemas cuando lo que no hay es busqueda es el
        // modo de fallo mas caro de una comprobacion automatica (`T-28`).
        $this->assertGreaterThan(100, $mirado,
            'Se miraron muy pocas rutas: el recorrido esta roto, no el muro.');

        // Y que la mayoria conteste 403 DE VERDAD. Sin esto, el dia que el
        // enlace de modelo se adelantara en todas las rutas, la prueba seguiria
        // verde sin que el permiso hubiera contestado ni una vez.
        $this->assertGreaterThan(80, $negadas,
            sprintf('Solo %d de %d rutas contestaron 403: el permiso apenas '
                .'esta contestando y el resto se esta yendo en 404.', $negadas, $mirado));
    }

    /** @param list<string> $middleware */
    private function exigePermiso(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'permiso:')) {
                return true;
            }
        }

        return false;
    }

    private function url(\Illuminate\Routing\Route $ruta): string
    {
        $parametros = [];

        foreach ($ruta->parameterNames() as $parametro) {
            // El valor da igual: el middleware de permiso corre ANTES del
            // controlador, asi que un 403 llega sin tocar la base. Lo que si
            // importa es que case con el `where` de la ruta, o Laravel devuelve
            // 404 antes de llegar al middleware y la prueba mediria otra cosa.
            $parametros[$parametro] = str_contains(mb_strtolower($parametro), 'uuid')
                ? (string) Str::uuid()
                : (in_array($parametro, ['token'], true) ? Str::random(40) : '999999');
        }

        return route($ruta->getName(), $parametros, false);
    }

    /** Un creador de verdad detras del rol `creator`, no un usuario suelto. */
    private function comoRol(?string $rol): User
    {
        $usuario = $this->usuarioCon($rol);

        if ($rol === 'creator') {
            DB::table('creators')->where('id', $this->creadorActivo())
                ->update(['user_id' => $usuario->id]);
        }

        return $usuario;
    }
}
