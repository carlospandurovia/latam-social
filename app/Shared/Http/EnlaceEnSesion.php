<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\Request;

/**
 * El token de un enlace no se queda en la barra de direcciones (`DEC-117`).
 *
 * Lo usan las dos pantallas públicas del sistema —poner una contraseña (`4.1`) y
 * contestar una invitación (`7.6`)— y hacen lo mismo: la ruta que lleva el token
 * lo guarda aquí y redirige a una URL limpia.
 *
 * ### Por qué, otra vez
 *
 * Una URL con un token dentro:
 *
 * - viaja en la cabecera `Referer` a **cualquier recurso externo de la página**, y
 *   estas pantallas cargan tipografías de un dominio de terceros;
 * - se queda en el registro de accesos del servidor y en el historial;
 * - se copia entera cuando la persona la pega en un chat preguntando qué es.
 *
 * ### `invalidate()` y no `regenerate()`
 *
 * El segundo cambia el identificador de sesión y **conserva el contenido**. Aquí
 * el contenido es justo lo que sobra: quien haya conseguido plantar una cookie de
 * sesión en el navegador de la víctima puede haber dejado cosas dentro, y sin
 * vaciarla leería el token que la víctima acaba de guardar ahí.
 *
 * ### Esto vive en `Shared` y no en un controlador
 *
 * Porque en cuanto hubo una segunda pantalla eran seis líneas repetidas, y seis
 * líneas de seguridad repetidas son seis líneas que un día se arreglan en un
 * sitio. Identity y Campaign pueden ver `Shared`; entre ellos no se ven.
 */
trait EnlaceEnSesion
{
    /** Guarda el token en una sesión nueva y vacía. */
    protected function guardarToken(Request $peticion, string $token): void
    {
        $peticion->session()->invalidate();
        $peticion->session()->put($this->claveDeSesion(), $token);
    }

    /** El token guardado, o cadena vacía si no hay rastro. */
    protected function tokenDeSesion(Request $peticion): string
    {
        return (string) $peticion->session()->get($this->claveDeSesion(), '');
    }

    /**
     * Lo olvida. Se llama **salga bien o mal**: un token que sobrevive a su
     * intento es una segunda oportunidad que no debería existir.
     */
    protected function olvidarToken(Request $peticion): void
    {
        $peticion->session()->forget($this->claveDeSesion());
    }

    /** Dónde lo guarda cada pantalla. Distinta clave, distinto trámite. */
    abstract protected function claveDeSesion(): string;
}
