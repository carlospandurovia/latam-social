<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * Un comprobante, **sin país dentro** (9.9d).
 *
 * Es lo que cruza la frontera de `DEC-252`: quien arma el XML de un país recibe
 * esto, y quien lo prepara no sabe qué librería hay al otro lado.
 *
 * ### Por qué los importes son cadenas
 *
 * Vienen de `DECIMAL(18,4)`. Convertirlos a `float` por el camino es meter un
 * error de redondeo justo en las cifras que una fiscalización compara con la
 * declaración. Se pasan tal cual salieron de la base y **el adaptador decide**
 * en qué formato los quiere su librería — porque ahí sí hay una conversión
 * inevitable, y es mejor que ocurra en un solo sitio y a la vista.
 *
 * ### Por qué el tipo de identificación NO viene traducido
 *
 * Llega como lo guarda el sistema —`RUC`, `DNI`, `NIT`— y no como el `6` del
 * catálogo 06 de SUNAT. Traducirlo aquí sería meter a SUNAT dentro de la
 * estructura que existe para no tener a SUNAT dentro. Lo traduce el adaptador
 * peruano, que es el único que sabe qué catálogo es ése.
 */
final readonly class Comprobante
{
    /**
     * @param string $tipoOficial El código del tipo en el catálogo del país
     *                            (`document_types.official_code`): `01` factura,
     *                            `03` boleta. No se calcula aquí — se lee de la
     *                            fila, que es donde `DEC-190` dice que vive.
     * @param list<LineaDeComprobante> $lineas
     */
    public function __construct(
        public string $tipoOficial,
        public string $serie,
        public int $numero,
        public string $fechaEmision,
        /**
         * La zona del emisor. **No es un adorno:** `issue_date` es «el día en
         * la zona de la sociedad» (`2.3 §8`), y si el adaptador construye esa
         * fecha en otra zona, el comprobante sale con el día de antes. Pasó de
         * verdad la primera vez que se generó uno: `2026-09-01` en UTC se
         * escribió como `2026-08-31T19:00` en hora de Lima.
         */
        public string $zonaHoraria,
        public string $moneda,
        public string $regimen,
        public string $tasaImpuesto,
        public Parte $emisor,
        public Parte $receptor,
        public array $lineas,
        public string $subtotal,
        public string $impuesto,
        public string $total,
        public string $importeEnLetras,
    ) {}
}
