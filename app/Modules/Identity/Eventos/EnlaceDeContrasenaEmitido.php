<?php

declare(strict_types=1);

namespace App\Modules\Identity\Eventos;

/**
 * Se acaba de emitir un enlace de contraseña y hay que hacérselo llegar (`5.9`, `4.1`).
 *
 * ### Por qué este evento NO pasa por `Eventos::ocurrio()`
 *
 * Porque `Eventos::ocurrio()` **escribe el payload en `domain_events`**, y aquí
 * el payload lleva el enlace — o sea, el token en claro. Guardarlo tiraría por
 * tierra toda la decisión de la tabla: `password_links` guarda `token_sha256` y
 * no el token justamente para que un volcado de la base no sea una llave
 * maestra. De nada sirve si el token está entero en la fila de al lado.
 *
 * Así que el token viaja **sólo por memoria**: de `EnlacesDeContrasena::emitir()`
 * al oyente, del oyente al cuerpo del correo, y del correo a la cola. En cuanto
 * el correo sale, no queda en ningún sitio del que se pueda recuperar.
 *
 * El hecho —*«se emitió un enlace de tipo X para el usuario Y»*— sí se registra
 * en `domain_events`, pero por separado y **sin el token**. Esas dos frases no
 * son la misma y no pueden compartir fila.
 *
 * ### Por qué el enlace ya viene montado
 *
 * Communication no conoce los nombres de ruta de Identity, y no tiene por qué:
 * si un día la URL de recuperación cambia, cambia en un sitio. Lo que recibe es
 * una cadena que va tal cual dentro del correo.
 */
final class EnlaceDeContrasenaEmitido
{
    public function __construct(
        public readonly int $usuarioId,
        public readonly string $destinatario,
        public readonly string $nombre,
        /** `initial` (alta) o `reset` (recuperacion). */
        public readonly string $proposito,
        /** La URL completa, con el token dentro. No se persiste en ningun sitio. */
        public readonly string $enlace,
        /** Cuando caduca, ya formateada para leerse: el correo no formatea nada. */
        public readonly string $caduca,
        public readonly int $horas,
        public readonly string $idioma,
    ) {}
}
