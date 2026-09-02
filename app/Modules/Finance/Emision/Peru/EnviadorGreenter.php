<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision\Peru;

use App\Modules\Finance\Emision\CredencialesDeEnvio;
use App\Modules\Finance\Emision\EnviadorDeComprobante;
use App\Modules\Finance\Emision\RespuestaDeEnvio;
use Closure;
use Greenter\Ws\Services\BillSender;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\WsClientInterface;
use Throwable;

/**
 * La entrega a SUNAT, con Greenter (9.9e).
 *
 * Como `ArmadorGreenter`, **este archivo es de los únicos que pueden nombrar a
 * Greenter**, y lo impone `deptrac.yaml`, no un acuerdo.
 *
 * ### Dónde está la costura, y por qué está ahí
 *
 * `BillSender` acepta un `WsClientInterface`. Todo lo que hace —comprimir el
 * XML en el ZIP con el nombre exacto, llamar a `sendBill`, sacar el CDR del ZIP
 * que vuelve y leer su código— pasa por encima de esa interfaz. **Lo único que
 * necesita la extensión `soap` de PHP es construir el cliente concreto**, y eso
 * vive en un `Closure` de una línea que se puede sustituir.
 *
 * No es un truco para las pruebas: es dónde está de verdad la frontera entre
 * *«qué le mando a SUNAT y qué entiendo de lo que contesta»* —que es nuestro
 * problema, y se comprueba— y *«el cable»*, que es del sistema operativo.
 *
 * ### Los códigos de SUNAT, que son de SUNAT y viven aquí
 *
 * | Código | Qué significa | Qué se hace |
 * |---|---|---|
 * | `0` sin notas | Aceptado | Nada |
 * | `0` con notas | Aceptado con observaciones | Mirarlas |
 * | `2000`–`3999` | **Rechazado**: el documento no existe para SUNAT | Corregir y emitir otro |
 * | `0100`–`0999` | Error del sistema de SUNAT | **Reintentar** |
 *
 * La diferencia entre las dos últimas es la que más cara sale confundir.
 * Reintentar un rechazo da el mismo rechazo; no reintentar un error de sistema
 * deja un comprobante emitido que nunca llegó.
 */
final class EnviadorGreenter implements EnviadorDeComprobante
{
    /** Desde este codigo hacia arriba, el documento fue RECHAZADO. */
    private const RECHAZO_DESDE = 2000;

    /** Y a partir de aqui, aceptado con observaciones. */
    private const OBSERVACION_DESDE = 4000;

    private const SEGUNDOS = 30;

    /** @var Closure(CredencialesDeEnvio): WsClientInterface */
    private Closure $cliente;

    /**
     * @param null|Closure(CredencialesDeEnvio): WsClientInterface $cliente
     *                                                                      La costura. En producción, el `SoapClient` de Greenter; en las
     *                                                                      pruebas, un transporte que devuelve el ZIP que se le dé.
     */
    public function __construct(?Closure $cliente = null)
    {
        $this->cliente = $cliente ?? static function (CredencialesDeEnvio $c): WsClientInterface {
            $soap = new SoapClient($c->url.'?wsdl', [
                'trace' => false,
                'exceptions' => true,
                'connection_timeout' => self::SEGUNDOS,
                // `trace` apagado a proposito: guardaria el sobre SOAP entero
                // en memoria, y ese sobre lleva la clave SOL dentro.
            ]);
            // SUNAT autentica con el RUC y el usuario secundario PEGADOS.
            // Es una rareza suya --`20603203896MODDATOS`-- y por eso la junta
            // el adaptador y no quien le pasa las credenciales.
            $soap->setCredentials($c->identificadorEmisor.$c->usuario, $c->clave);

            return $soap;
        };
    }

    public function pais(): string
    {
        return 'PE';
    }

    public function porQueNoPuede(): ?string
    {
        if (!extension_loaded('soap')) {
            return 'Este servidor no puede hablar con SUNAT: le falta la extension «soap» de PHP. '
                .'En cPanel se activa en «Select PHP Version».';
        }

        return null;
    }

    public function envia(string $nombre, string $xml, CredencialesDeEnvio $credenciales): RespuestaDeEnvio
    {
        if (($falta = $this->porQueNoPuede()) !== null) {
            return new RespuestaDeEnvio(
                estado: RespuestaDeEnvio::NO_CONFIGURADO,
                codigo: null,
                descripcion: $falta,
            );
        }

        // `basename` sin extension: `BillSender` le pega el `.xml` y el `.zip`,
        // y pasarle el nombre con extension produce `...xml.zip`, que SUNAT
        // rechaza sin decir que el problema es el nombre.
        $base = preg_replace('/\.xml$/i', '', $nombre) ?? $nombre;

        try {
            $emisor = new BillSender;
            $emisor->setClient(($this->cliente)($credenciales));
            $resultado = $emisor->send($base, $xml);
        } catch (Throwable $e) {
            // Cualquier cosa que se rompa ANTES de tener respuesta es «no se
            // llego a saber», y eso se reintenta. Incluye que el WSDL no
            // conteste, que el certificado TLS del otro lado no valide, o que
            // la maquina no tenga salida.
            return new RespuestaDeEnvio(
                estado: RespuestaDeEnvio::ERROR_RED,
                codigo: null,
                descripcion: 'No se pudo hablar con SUNAT: '.mb_substr($e->getMessage(), 0, 180),
            );
        }

        if ($resultado === null) {
            return new RespuestaDeEnvio(
                estado: RespuestaDeEnvio::ERROR_RED,
                codigo: null,
                descripcion: 'SUNAT no devolvio ninguna respuesta.',
            );
        }

        if (!$resultado->isSuccess()) {
            return $this->delError($resultado->getError());
        }

        return $this->delCdr($resultado, $base);
    }

    private function delError(mixed $error): RespuestaDeEnvio
    {
        $codigo = $error === null ? null : (string) $error->getCode();
        $mensaje = $error === null ? 'SUNAT rechazo el envio sin detalle.' : (string) $error->getMessage();
        $numero = (int) $codigo;

        // Un codigo 2000+ es el documento: no se reintenta. Cualquier otro es
        // el servicio o el transporte, y si.
        $estado = $numero >= self::RECHAZO_DESDE
            ? RespuestaDeEnvio::RECHAZADO
            : RespuestaDeEnvio::ERROR_RED;

        return new RespuestaDeEnvio(
            estado: $estado,
            codigo: $codigo,
            descripcion: mb_substr($mensaje, 0, 255),
        );
    }

    private function delCdr(mixed $resultado, string $base): RespuestaDeEnvio
    {
        $cdr = $resultado->getCdrResponse();
        $codigo = $cdr === null ? null : (string) $cdr->getCode();
        $numero = (int) $codigo;
        /** @var list<string> $notas */
        $notas = array_values(array_map(
            static fn (mixed $n): string => mb_substr((string) $n, 0, 255),
            $cdr?->getNotes() ?? [],
        ));

        if ($numero >= self::RECHAZO_DESDE && $numero < self::OBSERVACION_DESDE) {
            $estado = RespuestaDeEnvio::RECHAZADO;
        } elseif ($numero >= self::OBSERVACION_DESDE || $notas !== []) {
            // Las notas son lo que convierte «aceptado» en «aceptado, pero
            // mira esto»: el proximo comprobante con el mismo defecto puede no
            // entrar, y enterarse entonces es tarde.
            $estado = RespuestaDeEnvio::OBSERVADO;
        } else {
            $estado = RespuestaDeEnvio::ACEPTADO;
        }

        return new RespuestaDeEnvio(
            estado: $estado,
            codigo: $codigo,
            descripcion: mb_substr((string) ($cdr?->getDescription() ?? ''), 0, 255),
            notas: $notas,
            cdr: $resultado->getCdrZip() === null ? null : (string) $resultado->getCdrZip(),
            nombreCdr: 'R-'.$base.'.zip',
        );
    }
}
