<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\FijarPasswordRequest;
use App\Modules\Identity\Http\Requests\PedirEnlaceRequest;
use App\Modules\Identity\Services\EnlacesDeContrasena;
use App\Shared\Http\EnlaceEnSesion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Pedir y usar un enlace de contraseña (`4.1`, y la otra mitad de `5.9`).
 *
 * ### La respuesta es la misma exista el correo o no
 *
 * Decisión de negocio (2026-08-26). *«Si ese correo tiene cuenta, le acaba de
 * llegar un enlace»* se contesta igual a los dos, y con el mismo retraso
 * aparente. Distinguirlos convierte esta pantalla en un buscador de clientes
 * nuestros: alguien con una lista de direcciones sabría en un rato cuáles están
 * dadas de alta, y eso vale dinero para quien hace phishing dirigido.
 *
 * El precio es real y hay que decirlo: **quien se equivoca de correo no se
 * entera**, se queda esperando un correo que no llega. Se compensa diciendo en
 * la propia pantalla qué hacer si no llega en unos minutos.
 *
 * ### El token NO se queda en la barra de direcciones
 *
 * El enlace del correo lleva el token —no hay otra forma—, pero lo primero que
 * hace esa ruta es **guardarlo en la sesión y redirigir a una URL sin token**.
 *
 * No es cosmético. Una URL con un token dentro:
 *
 * - viaja en la cabecera `Referer` a **cualquier recurso externo de la página**
 *   —y la pantalla de acceso carga tipografías de un dominio de terceros—;
 * - se queda en el registro de accesos del servidor, en el historial del
 *   navegador y en la barra que alguien fotografía para pedir ayuda;
 * - se copia entera cuando la persona la pega en un chat preguntando qué es.
 *
 * Con la redirección, el token vive un instante en una petición y el resto del
 * tiempo en una cookie de sesión, que es donde viven las credenciales.
 *
 * Y la sesión **se vacía y se renueva** al guardarlo: si no, alguien que hubiera
 * fijado el identificador de sesión de la víctima podría leer el token que la
 * víctima acaba de meter ahí. Se descarta también lo que hubiera dentro —incluida
 * una sesión autenticada de otra persona en un ordenador prestado—, porque nada
 * de eso hace falta para poner una contraseña.
 */
final class RecuperacionController
{
    // El token no se queda en la URL. El mecanismo --y el porque-- viven en
    // `Shared`, porque `7.6` hace exactamente lo mismo con el enlace de una
    // invitacion y seis lineas de seguridad repetidas son seis lineas que un dia
    // se arreglan en un sitio.
    use EnlaceEnSesion;

    public function pedir(): View
    {
        return view('acceso.recuperar');
    }

    public function enviar(PedirEnlaceRequest $peticion): RedirectResponse
    {
        /** @var array<string, string> $datos */
        $datos = $peticion->validated();
        $email = mb_strtolower(trim($datos['email']));

        // Un limite POR CORREO, ademas del de la ruta por IP. Sin el, cualquiera
        // puede inundar el buzon de una persona concreta desde IPs distintas: no
        // le roba la cuenta, pero le hace la vida imposible y entrena a su
        // proveedor para marcarnos como correo basura.
        //
        // No filtra nada: la respuesta es identica se pase o no del limite.
        $llave = 'enlace-contrasena:'.hash('sha256', $email);

        if (!RateLimiter::tooManyAttempts($llave, 3)) {
            RateLimiter::hit($llave, 3600);
            $this->emitirSiExiste($email);
        }

        return redirect()->route('recuperar')->with('enviado', true);
    }

    /**
     * Abre el enlace del correo: valida, guarda el token en la sesión y
     * redirige a una URL limpia.
     */
    public function usar(Request $peticion, string $token): RedirectResponse
    {
        $resultado = EnlacesDeContrasena::validar($token);

        if (!$resultado['ok']) {
            return redirect()->route('recuperar')->with(
                'fallo',
                EnlacesDeContrasena::MOTIVOS[$resultado['motivo']] ?? 'Este enlace no sirve.',
            );
        }

        $this->guardarToken($peticion, $token);

        return redirect()->route('recuperar.formulario');
    }

    public function formulario(Request $peticion): View|RedirectResponse
    {
        $token = $this->tokenDeSesion($peticion);
        // «No hay token en la sesion» y «el token no vale» son dos cosas
        // distintas y la persona tiene que hacer algo distinto en cada una:
        // volver a abrir el enlace, o pedir otro. Devolver el mismo texto para
        // las dos --que es como estaba-- manda a pedir un enlace nuevo a quien
        // ya tiene uno bueno en el correo.
        $resultado = $token === ''
            ? ['ok' => false, 'motivo' => 'sesion_perdida', 'enlace' => null]
            : EnlacesDeContrasena::validar($token);

        if (!$resultado['ok']) {
            $this->olvidarToken($peticion);

            return redirect()->route('recuperar')->with(
                'fallo',
                EnlacesDeContrasena::MOTIVOS[$resultado['motivo']] ?? 'Este enlace no sirve.',
            );
        }

        $enlace = $resultado['enlace'];

        return view('acceso.fijar', [
            // La pantalla dice a QUE cuenta va a afectar. Alguien que gestiona
            // dos cuentas y abre el correo equivocado tiene que poder darse
            // cuenta antes de teclear.
            'correo' => (string) $enlace->email,
            'nombre' => (string) $enlace->name,
            // El texto no es el mismo: estrenar cuenta y recuperar una que ya
            // usabas son dos situaciones distintas y la segunda asusta.
            'inicial' => $enlace->purpose === 'initial',
        ]);
    }

    public function fijar(FijarPasswordRequest $peticion): RedirectResponse
    {
        $token = $this->tokenDeSesion($peticion);

        if ($token === '') {
            return redirect()->route('recuperar')
                ->with('fallo', EnlacesDeContrasena::MOTIVOS['sesion_perdida']);
        }

        /** @var array<string, string> $datos */
        $datos = $peticion->validated();

        $resultado = EnlacesDeContrasena::consumir($token, $datos['password'], $peticion->ip());

        // Se olvida SIEMPRE, salga bien o mal.
        $this->olvidarToken($peticion);

        if (!$resultado['ok']) {
            return redirect()->route('recuperar')->with(
                'fallo',
                EnlacesDeContrasena::MOTIVOS[$resultado['motivo']] ?? 'Este enlace no sirve.',
            );
        }

        // No se le deja entrar automaticamente. Que teclee la contrasena que
        // acaba de poner es la unica forma de que se entere de que la tiene
        // bien, y evita que un enlace abierto en un ordenador prestado deje una
        // sesion viva detras.
        return redirect()->route('acceso')->with(
            'exito',
            'Contrasena guardada. Ya puedes entrar con ella.',
        );
    }

    /**
     * Emite el enlace si el correo corresponde a una cuenta que puede usarlo.
     *
     * Devuelve `void` a propósito: quien la llama **no debe poder** distinguir
     * los casos, porque entonces sería cuestión de tiempo que un `if` acabara
     * enseñándolos.
     */
    protected function claveDeSesion(): string
    {
        return 'enlace_contrasena';
    }

    private function emitirSiExiste(string $email): void
    {
        $usuario = DB::table('users')
            ->where('email', $email)
            ->where('status', 'active')
            ->first(['id']);

        if ($usuario === null) {
            // Ni excepcion ni aviso al usuario. Se registra en el log del
            // servidor, que es donde importa: una racha de peticiones para
            // correos que no existen es un barrido, y se ve aqui.
            Log::info('Peticion de recuperacion para un correo sin cuenta activa.');

            return;
        }

        EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');
    }
}
