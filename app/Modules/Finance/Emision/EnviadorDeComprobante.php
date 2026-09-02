<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * La otra mitad de la frontera de `DEC-252` (9.9e).
 *
 * `ArmadorDeComprobante` construye el documento; esto lo entrega. Se separan
 * porque **fallan por motivos distintos y se arreglan en sitios distintos**: no
 * poder armar un XML es un dato que falta en el sistema; no poder entregarlo es
 * la red, el servicio del otro lado o una credencial. Juntarlos haría que un
 * corte de red pareciera un error de datos.
 */
interface EnviadorDeComprobante
{
    /**
     * Entrega el comprobante y devuelve lo que contestó la administración.
     *
     * **No lanza por un rechazo.** Un rechazo es una respuesta, no un fallo del
     * programa: hay que guardarla, enseñarla y no reintentarla. Lanzar obligaría
     * a quien llama a distinguir excepciones para saber qué pasó, que es
     * justamente lo que `RespuestaDeEnvio` evita.
     */
    public function envia(string $nombre, string $xml, CredencialesDeEnvio $credenciales): RespuestaDeEnvio;

    /** Para qué país sirve. ISO de dos letras, en mayúsculas. */
    public function pais(): string;

    /**
     * `null` si este servidor puede enviar; el motivo en palabras si no.
     *
     * Existe por la misma razón que `Esquema` en `9.17j`: **el sistema tiene que
     * notar lo que le falta antes de que falle**. Enviar a SUNAT necesita la
     * extensión `soap` de PHP, y un servidor sin ella no da un error que lo
     * diga: da uno que habla de una clase que no existe.
     */
    public function porQueNoPuede(): ?string;
}
