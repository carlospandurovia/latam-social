<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision\Peru;

use App\Modules\Finance\Emision\ArmadorDeComprobante;
use App\Modules\Finance\Emision\Comprobante;
use App\Modules\Finance\Emision\DocumentoArmado;
use App\Modules\Finance\Emision\LineaDeComprobante;
use DateTime;
use DateTimeZone;
use Greenter\Factory\FeFactory;
use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use RuntimeException;
use Throwable;

/**
 * El UBL 2.1 de SUNAT, con Greenter (9.9d).
 *
 * **Este archivo es el único de todo el proyecto que puede nombrar a Greenter**,
 * y no por acuerdo: `deptrac.yaml` declara la capa `Greenter` y sólo esta
 * carpeta la tiene permitida. Es `DEC-252` convertido en una puerta.
 *
 * ### Por qué NO se usa `Greenter\See`
 *
 * `See` es la fachada obvia, y es una trampa aquí: **su constructor crea un
 * `SoapClient`**, así que usarla para *armar* un XML obligaría a tener
 * `ext-soap` en cualquier máquina que sólo quiera generar un comprobante. Se
 * usan las tres piezas que hacen falta —el resolutor de plantillas, la fábrica
 * y el firmador— y `ext-soap` pasa a ser un requisito de `9.9e`, que es cuando
 * de verdad se habla con SUNAT.
 *
 * ### Lo que sí es de SUNAT y vive aquí
 *
 * El catálogo 06 de tipos de identificación, el catálogo 07 de afectación al
 * IGV, el `tipoOperacion` y **el nombre del archivo** —`RUC-TIPO-SERIE-NUMERO`,
 * que SUNAT usa para identificar el documento dentro del ZIP y rechaza sin
 * explicar si no cuadra con el contenido—. Nada de eso es configuración de esta
 * instalación: son constantes públicas de una administración, así que van en
 * código y no en una pantalla (`DEC-255`, la misma lección que las direcciones).
 *
 * ### 🔴 Lo que NO está revisado por un contador (§56)
 *
 * La exportación de servicios se arma con `tipoOperacion 0200` y afectación
 * `40`. Es lo que la documentación de SUNAT y los ejemplos de Greenter usan,
 * **pero no lo ha revisado un profesional**, y de eso depende que una factura de
 * exportación sea válida. Queda como `Q-67`.
 */
final class ArmadorGreenter implements ArmadorDeComprobante
{
    /** Catalogo 06 de SUNAT: que documento identifica al receptor. */
    private const IDENTIFICACION = [
        'RUC' => '6',
        'DNI' => '1',
        'CE' => '4',
        'PASAPORTE' => '7',
    ];

    /** Catalogo 07: como se afecta al IGV. */
    private const AFECTACION_GRAVADO = '10';

    private const AFECTACION_EXPORTACION = '40';

    /** Catalogo 51: que clase de operacion es. */
    private const OPERACION_INTERNA = '0101';

    private const OPERACION_EXPORTACION = '0200';

    /** Catalogo 52: la leyenda del importe en letras. */
    private const LEYENDA_IMPORTE = '1000';

    /**
     * Unidad de medida (catalogo 03). `ZZ` es «servicio», que es lo único que
     * factura esta plataforma: una campaña no se mide en kilos.
     */
    private const UNIDAD_SERVICIO = 'ZZ';

    public function pais(): string
    {
        return 'PE';
    }

    public function arma(Comprobante $comprobante, string $pem): DocumentoArmado
    {
        $exportacion = $comprobante->regimen === 'exportacion';

        $factura = (new Invoice)
            ->setUblVersion('2.1')
            ->setTipoOperacion($exportacion ? self::OPERACION_EXPORTACION : self::OPERACION_INTERNA)
            ->setTipoDoc($comprobante->tipoOficial)
            ->setSerie($comprobante->serie)
            ->setCorrelativo((string) $comprobante->numero)
            // CON la zona del emisor. Sin ella, `2026-09-01` construido en UTC
            // se escribe como `2026-08-31T19:00` en hora de Lima, y el
            // comprobante declara el dia de antes. Salio la primera vez que se
            // genero uno de verdad: ninguna prueba de unidad lo habria visto,
            // porque el fallo esta en como lo RENDERIZA Greenter.
            ->setFechaEmision(new DateTime(
                $comprobante->fechaEmision.' 00:00:00',
                new DateTimeZone($comprobante->zonaHoraria),
            ))
            ->setTipoMoneda($comprobante->moneda)
            ->setCompany($this->emisor($comprobante))
            ->setClient($this->receptor($comprobante))
            ->setTotalImpuestos((float) $comprobante->impuesto)
            ->setValorVenta((float) $comprobante->subtotal)
            ->setSubTotal((float) $comprobante->total)
            ->setMtoImpVenta((float) $comprobante->total)
            ->setDetails($this->lineas($comprobante, $exportacion))
            ->setLegends([
                (new Legend)
                    ->setCode(self::LEYENDA_IMPORTE)
                    ->setValue($comprobante->importeEnLetras),
            ]);

        // Gravado y exportacion no ocupan la misma casilla: el importe de una
        // exportacion NO es «operacion gravada con IGV al 0 %», es otra base.
        // Meterlo en la casilla equivocada cuadra el total y descuadra la
        // declaracion, que es peor que fallar.
        if ($exportacion) {
            $factura->setMtoOperExportacion((float) $comprobante->subtotal);
        } else {
            $factura->setMtoOperGravadas((float) $comprobante->subtotal)
                ->setMtoIGV((float) $comprobante->impuesto);
        }

        $xml = $this->firmar($factura, $pem);

        return new DocumentoArmado(
            nombre: $this->nombreDelArchivo($comprobante),
            xml: $xml,
            huella: hash('sha256', $xml),
        );
    }

    private function firmar(Invoice $factura, string $pem): string
    {
        $firmador = new SignedXml;
        $firmador->setCertificate($pem);

        $fabrica = new FeFactory;
        $fabrica->setSigner($firmador);

        // `autoescape` apagado y `cache` en false: es lo que hace `See` por
        // dentro. La cache de Twig escribe en disco, y aqui no hace falta --se
        // arma un documento por emision, no mil por segundo--.
        $resolutor = new XmlBuilderResolver(['autoescape' => false, 'cache' => false]);

        try {
            $xml = $fabrica->setBuilder($resolutor->find($factura::class))->getXmlSigned($factura);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'No se pudo armar el comprobante: '.mb_substr($e->getMessage(), 0, 200),
                previous: $e,
            );
        }

        if ($xml === null || trim($xml) === '') {
            throw new RuntimeException('El comprobante salio vacio y eso no puede pasar en silencio.');
        }

        return $xml;
    }

    private function emisor(Comprobante $c): Company
    {
        $e = $c->emisor;

        return (new Company)
            ->setRuc($e->numeroIdentificacion)
            ->setRazonSocial($e->razonSocial)
            ->setNombreComercial($e->nombreComercial ?? $e->razonSocial)
            ->setAddress((new Address)
                // Si falta el ubigeo, SUNAT rechaza. Se manda vacio y que lo
                // diga ELLA: inventar «150101» seria declarar un domicilio que
                // no es. Lo que evita llegar aqui sin ubigeo es el aviso de la
                // pantalla, no un valor de relleno.
                ->setUbigueo($e->ubigeo ?? '')
                ->setDistrito($e->distrito ?? '')
                ->setProvincia($e->provincia ?? '')
                ->setDepartamento($e->departamento ?? '')
                ->setUrbanizacion('-')
                ->setCodLocal($e->codigoLocal ?? '0000')
                ->setDireccion($e->direccion));
    }

    private function receptor(Comprobante $c): Client
    {
        $r = $c->receptor;
        $tipo = self::IDENTIFICACION[strtoupper($r->tipoIdentificacion)] ?? null;

        // `0` es «otros» en el catalogo 06, y es lo correcto para un cliente del
        // exterior: su NIT colombiano o su VAT espanol no estan en la lista de
        // SUNAT, y forzarlos a `6` (RUC) seria afirmar algo falso.
        if ($tipo === null) {
            $tipo = $r->paisIso === 'PE' ? '6' : '0';
        }

        return (new Client)
            ->setTipoDoc($tipo)
            ->setNumDoc($r->numeroIdentificacion)
            ->setRznSocial($r->razonSocial)
            ->setAddress((new Address)->setDireccion($r->direccion));
    }

    /** @return list<SaleDetail> */
    private function lineas(Comprobante $c, bool $exportacion): array
    {
        return array_map(
            fn (LineaDeComprobante $l): SaleDetail => (new SaleDetail)
                ->setUnidad(self::UNIDAD_SERVICIO)
                ->setCantidad((float) $l->cantidad)
                ->setDescripcion($l->descripcion)
                ->setMtoBaseIgv((float) $l->subtotal)
                ->setPorcentajeIgv((float) $l->tasa)
                ->setIgv((float) $l->impuesto)
                ->setTipAfeIgv($exportacion ? self::AFECTACION_EXPORTACION : self::AFECTACION_GRAVADO)
                ->setTotalImpuestos((float) $l->impuesto)
                ->setMtoValorVenta((float) $l->subtotal)
                ->setMtoValorUnitario((float) $l->precioUnitario)
                ->setMtoPrecioUnitario((float) $l->total / max((float) $l->cantidad, 1e-9)),
            $c->lineas,
        );
    }

    /**
     * `20603203896-01-F001-123.xml`.
     *
     * SUNAT identifica el documento por este nombre dentro del ZIP, y si no
     * cuadra con el contenido lo rechaza **sin decir que el problema es el
     * nombre**. Por eso se arma aqui, de las mismas cifras que van dentro, y no
     * lo escribe nadie.
     */
    private function nombreDelArchivo(Comprobante $c): string
    {
        return sprintf(
            '%s-%s-%s-%d.xml',
            $c->emisor->numeroIdentificacion,
            $c->tipoOficial,
            $c->serie,
            $c->numero,
        );
    }
}
