<?php

declare(strict_types=1);

namespace App\Shared\Eventos;

/**
 * Se publicó una versión de unos términos (9.19b).
 *
 * ### Por qué un evento y no una llamada
 *
 * `Terminos::publicar()` vive en Core, y avisar a los creadores exige leer
 * `creators` — que es de Creator. `deptrac.yaml` dice `Core: [Framework,
 * Shared]`, así que Core **no puede** llamar a Creator, ni directamente ni con
 * una consulta a su tabla (`T-74` ya fue eso mismo).
 *
 * Con un evento en `Shared`, Core dice *«pasó esto»* y no sabe quién escucha;
 * Creator escucha y no sabe quién lo levantó. Es el mismo patrón que
 * `CorreoPedido` y por el mismo motivo, y además tiene una consecuencia buena
 * que no es teórica: **si el envío de correos falla, la publicación ya está
 * hecha**. Un SMTP caído no puede tumbar la publicación de unos términos.
 *
 * ### Lleva `cambio`, y de eso depende todo
 *
 * `fondo` obliga a todos a reaceptar; `menor` no. El oyente decide con ese dato
 * si manda algo, en vez de mandarlo siempre y molestar a doscientas personas
 * por una errata corregida.
 */
final class TerminosPublicados
{
    public function __construct(
        public readonly int $versionId,
        public readonly string $codigo,
        public readonly string $version,
        public readonly string $titulo,
        /** `fondo`, `menor`, o `null` si es la primera versión y no releva a nadie. */
        public readonly ?string $cambio,
        public readonly string $audiencia,
        /** Cuántos días tienen para aceptarla. Va en el correo. */
        public readonly int $diasParaAceptar,
    ) {}
}
