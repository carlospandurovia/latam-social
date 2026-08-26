<?php

declare(strict_types=1);

namespace App\Shared\Eventos;

/**
 * Alguien necesita que salga un correo, y su contenido **no se puede guardar**.
 *
 * ### Por qué existe, y por qué no es `EventoOcurrido`
 *
 * `EventoOcurrido` (`DEC-112`) escribe su payload en `domain_events`, y eso es
 * una virtud: el hecho consta aunque el oyente reviente. Pero hay avisos cuyo
 * contenido es exactamente lo que no puede quedar guardado:
 *
 * | Quién | Qué lleva dentro |
 * |---|---|
 * | `5.9` / `4.1` | el enlace con el token de contraseña en claro |
 * | `7.6` | el enlace con el token de la invitación |
 *
 * Guardarlos tiraría por tierra la decisión de las dos tablas —`password_links` e
 * `invitations` guardan la **huella** del token y no el token— porque daría igual
 * cuánto se proteja una tabla si el valor está entero en la de al lado.
 *
 * Así que esto **no se persiste**: viaja por memoria, del emisor al oyente, del
 * oyente al cuerpo del correo y del correo a la cola. El HECHO —*«se emitió un
 * enlace de tipo X»*— sí se registra, por separado y sin el token.
 *
 * ### Y por qué vive en `Shared` y no en el módulo que lo levanta
 *
 * Porque `deptrac.yaml` dice `Communication: [Framework, Shared, Core, Identity]`.
 * En `5.9` el evento era de Identity y funcionaba de milagro: Identity está en
 * esa lista. **Campaign no**, y `7.6` necesita mandar un correo exactamente igual.
 *
 * La primera versión de esto vivió una iteración en
 * `App\Modules\Identity\Eventos\EnlaceDeContrasenaEmitido`. Cuando apareció el
 * segundo emisor quedó claro que el sitio era éste: en `Shared` lo puede levantar
 * cualquier módulo y Communication lo puede escuchar sin conocer a ninguno, que
 * es literalmente lo que pide `docs/03`.
 *
 * ### El código de plantilla lo pone quien pide el correo
 *
 * Y está bien: Communication sabe **cómo** se manda un correo, no **qué** texto
 * toca. `Correo::enviar()` ya recibe un código; esto sólo lo hace llegar hasta
 * allí sin que los dos módulos se conozcan.
 */
final class CorreoPedido
{
    /**
     * @param array<string, string|int|float> $variables lo que la plantilla espera
     */
    public function __construct(
        public readonly string $codigo,
        public readonly string $destinatario,
        public readonly array $variables = [],
        public readonly string $idioma = 'es',
        public readonly ?string $tipoRelacionado = null,
        public readonly ?int $idRelacionado = null,
    ) {}
}
