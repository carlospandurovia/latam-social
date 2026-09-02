<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Core\Services\Certificados;
use App\Modules\Finance\Emision\Armadores;
use App\Modules\Finance\Emision\Comprobante;
use App\Modules\Finance\Emision\LineaDeComprobante;
use App\Modules\Finance\Emision\Parte;
use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Texto\Letras;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * El comprobante electrónico de una factura emitida (9.9d).
 *
 * ### Lo que hace, y lo que deja para 9.9e
 *
 * Arma el XML, lo firma y **lo guarda**. No lo manda: hablar con SUNAT es otra
 * cosa —necesita `ext-soap`, la conexión, el usuario secundario y saber leer un
 * CDR— y mezclarlo aquí haría que un fallo de red pareciera un fallo al armar.
 *
 * ### El certificado se pide y no se guarda
 *
 * `Certificados::material()` devuelve el PEM en claro porque firmar exige la
 * clave privada. Vive en una variable el tiempo de armar el documento y no se
 * escribe en ningún sitio: ni en la bitácora, ni en el documento, ni en el log
 * (`BR-SEC-001`). Lo que sí queda es **con qué certificado se firmó**, por su
 * fila — la pregunta que hay que poder contestar el día que uno se revoque.
 *
 * ### Sólo de una factura EMITIDA
 *
 * Un borrador no tiene número, y sin número no hay comprobante que armar. Se
 * comprueba aquí y lo vuelve a comprobar la base con `ck_invoice_numerada`.
 */
final class Comprobantes
{
    public const XML_FIRMADO = 'xml_signed';

    /**
     * Genera el XML firmado de una factura y lo deja guardado.
     *
     * Devuelve el UUID del documento. Si ya había uno vigente, **queda
     * reemplazado y no se borra**: si esa versión ya se mandó, lo que SUNAT
     * tiene es ésa.
     */
    public static function generar(string $facturaUuid, int $usuarioId): string
    {
        $factura = self::facturaEmitida($facturaUuid);
        $comprobante = self::armarDatos($factura);

        $certificado = Certificados::vigente(
            (int) $factura->legal_entity_id,
            self::entornoDe($factura),
        );

        if ($certificado === null) {
            throw new RuntimeException(
                'No hay certificado de firma vigente para esa sociedad: sin el no se puede '
                .'firmar ningun comprobante.',
            );
        }

        $armador = Armadores::para((string) $factura->issuer_country_snapshot);
        $documento = $armador->arma($comprobante, Certificados::material((int) $certificado->id));

        $uuid = (string) Str::uuid();

        DB::transaction(function () use ($factura, $documento, $certificado, $usuarioId, $uuid): void {
            DB::table('electronic_documents')
                ->where('invoice_id', $factura->id)
                ->where('kind', self::XML_FIRMADO)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now(), 'updated_at' => now()]);

            DB::table('electronic_documents')->insert([
                'uuid' => $uuid,
                'invoice_id' => (int) $factura->id,
                'kind' => self::XML_FIRMADO,
                'name' => $documento->nombre,
                'xml_content' => $documento->xml,
                'sha256' => $documento->huella,
                'size_bytes' => strlen($documento->xml),
                'signing_certificate_id' => (int) $certificado->id,
                'generated_at' => now(),
                'generated_by_user_id' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // La HUELLA si entra en la bitacora, y el XML no: la huella prueba que
        // el documento no cambio, y el documento entero convertiria la bitacora
        // --que se exporta-- en una segunda copia de las facturas.
        Bitacora::registrar(
            accion: 'invoice.xml.generated',
            tipoEntidad: 'invoice',
            idEntidad: (int) $factura->id,
            cambios: [
                'documento' => ['antes' => null, 'despues' => $documento->nombre],
                'huella' => ['antes' => null, 'despues' => $documento->huella],
            ],
        );

        return $uuid;
    }

    /** El documento vigente de una factura, sin su XML. */
    public static function vigente(int $facturaId, string $clase = self::XML_FIRMADO): ?object
    {
        return DB::table('electronic_documents as ed')
            ->leftJoin('users as u', 'u.id', '=', 'ed.generated_by_user_id')
            ->where('ed.invoice_id', $facturaId)
            ->where('ed.kind', $clase)
            ->whereNull('ed.superseded_at')
            ->first(['ed.id', 'ed.uuid', 'ed.name', 'ed.sha256', 'ed.size_bytes',
                'ed.generated_at', 'ed.signing_certificate_id', 'u.name as autor']);
    }

    /**
     * Todos los de una factura, el vigente primero. Sin el XML.
     *
     * @return Collection<int, \stdClass>
     */
    public static function historial(int $facturaId): Collection
    {
        return DB::table('electronic_documents as ed')
            ->leftJoin('users as u', 'u.id', '=', 'ed.generated_by_user_id')
            ->where('ed.invoice_id', $facturaId)
            ->orderByRaw('ed.superseded_at IS NOT NULL')
            ->orderByDesc('ed.generated_at')
            ->get(['ed.uuid', 'ed.kind', 'ed.name', 'ed.sha256', 'ed.generated_at',
                'ed.superseded_at', 'u.name as autor']);
    }

    /**
     * El XML, para descargarlo. **Se comprueba la huella antes de devolverlo.**
     *
     * Si no cuadra, algo lo tocó por debajo del disparador —una restauración a
     * medias, una edición directa— y devolverlo en silencio sería entregar como
     * prueba algo que ya no lo es.
     */
    public static function xml(string $documentoUuid): object
    {
        $fila = DB::table('electronic_documents')->where('uuid', $documentoUuid)
            ->first(['name', 'xml_content', 'sha256']);

        if ($fila === null) {
            throw new RuntimeException('No existe ese documento.');
        }

        if (hash('sha256', (string) $fila->xml_content) !== $fila->sha256) {
            throw new RuntimeException(
                'La huella del documento no cuadra con su contenido: alguien lo altero fuera '
                .'de la aplicacion. No se entrega.',
            );
        }

        return $fila;
    }

    // ------------------------------------------------------------- los datos

    private static function armarDatos(object $factura): Comprobante
    {
        $lineas = DB::table('invoice_lines')->where('invoice_id', $factura->id)
            ->orderBy('line_number')
            ->get(['line_number', 'description', 'quantity', 'unit_price',
                'line_subtotal', 'tax_rate', 'line_tax', 'line_total']);

        if ($lineas->isEmpty()) {
            throw new RuntimeException('Una factura sin lineas no arma ningun comprobante.');
        }

        return new Comprobante(
            tipoOficial: self::codigoOficial($factura),
            serie: (string) $factura->series,
            numero: (int) $factura->number,
            fechaEmision: (string) $factura->issue_date,
            zonaHoraria: (string) (DB::table('legal_entities')
                ->where('id', $factura->legal_entity_id)->value('timezone') ?: 'UTC'),
            moneda: (string) $factura->currency_code,
            regimen: (string) $factura->tax_regime,
            tasaImpuesto: (string) ($factura->tax_rate_snapshot ?? '0'),
            emisor: self::emisor($factura),
            receptor: self::receptor($factura),
            lineas: $lineas->map(fn (object $l): LineaDeComprobante => new LineaDeComprobante(
                numero: (int) $l->line_number,
                descripcion: (string) $l->description,
                cantidad: (string) $l->quantity,
                precioUnitario: (string) $l->unit_price,
                subtotal: (string) $l->line_subtotal,
                impuesto: (string) $l->line_tax,
                total: (string) $l->line_total,
                tasa: (string) $l->tax_rate,
            ))->values()->all(),
            subtotal: (string) $factura->subtotal_amount,
            impuesto: (string) $factura->tax_amount,
            total: (string) $factura->total_amount,
            importeEnLetras: Letras::importe(
                (string) $factura->total_amount,
                (string) $factura->currency_code,
            ),
        );
    }

    /**
     * El código del tipo en el catálogo del país.
     *
     * Se LEE de `document_types.official_code` y no se decide aquí: es
     * exactamente lo que `DEC-190` pide, y lo que hace que la boleta peruana y
     * la factura electrónica colombiana quepan sin tocar este método.
     */
    private static function codigoOficial(object $factura): string
    {
        $codigo = DB::table('document_types as dt')
            ->join('countries as c', 'c.id', '=', 'dt.country_id')
            ->where('dt.code', $factura->document_type)
            ->where('c.iso2', $factura->issuer_country_snapshot)
            ->value('dt.official_code');

        if ($codigo === null || trim((string) $codigo) === '') {
            throw new RuntimeException(sprintf(
                'El tipo de comprobante «%s» no declara su codigo oficial en %s: sin el, la '
                .'administracion no sabe que documento esta recibiendo.',
                (string) $factura->document_type,
                (string) $factura->issuer_country_snapshot,
            ));
        }

        return (string) $codigo;
    }

    /**
     * El emisor, **todo desde las copias congeladas de la factura** (9.9f).
     *
     * Hasta `9.9f` la localidad se leia de `legal_entities`, que esta viva.
     * Regenerar el XML de una factura del ano pasado despues de que la sociedad
     * se mudara producia un documento DISTINTO del que se emitio --y los dos
     * van firmados, asi que no pueden ser los dos validos para lo mismo--.
     *
     * Lo unico que sigue saliendo de la tabla viva es el **nombre comercial**, y
     * a proposito: no es un dato fiscal --el RUC y la razon social si estan
     * congelados-- y es el rotulo con el que la empresa se presenta hoy.
     */
    private static function emisor(object $factura): Parte
    {
        $sociedad = DB::table('legal_entities')->where('id', $factura->legal_entity_id)
            ->first(['trade_name', 'tax_id_type']);

        return new Parte(
            tipoIdentificacion: (string) ($sociedad->tax_id_type ?? 'RUC'),
            numeroIdentificacion: (string) $factura->issuer_tax_id_snapshot,
            razonSocial: (string) $factura->issuer_legal_name_snapshot,
            direccion: (string) $factura->issuer_address_snapshot,
            paisIso: (string) $factura->issuer_country_snapshot,
            nombreComercial: $sociedad->trade_name ?? null,
            ubigeo: $factura->issuer_tax_location_snapshot,
            distrito: $factura->issuer_district_snapshot,
            provincia: $factura->issuer_province_snapshot,
            departamento: $factura->issuer_region_snapshot,
            codigoLocal: $factura->issuer_establishment_snapshot,
        );
    }

    private static function receptor(object $factura): Parte
    {
        $perfil = DB::table('client_tax_profiles')->where('id', $factura->client_tax_profile_id)
            ->first(['tax_id_type']);

        return new Parte(
            tipoIdentificacion: (string) ($perfil->tax_id_type ?? ''),
            numeroIdentificacion: (string) $factura->receiver_tax_id_snapshot,
            razonSocial: (string) $factura->receiver_legal_name_snapshot,
            direccion: (string) $factura->receiver_address_snapshot,
            paisIso: (string) $factura->receiver_country_snapshot,
        );
    }

    /**
     * Con qué certificado se firma: el del entorno de la SERIE que se usó.
     *
     * Una serie de pruebas se firma con el certificado de pruebas. Emitir en
     * producción con el de pruebas produce comprobantes que SUNAT rechaza, y el
     * error que devuelve no dice que el problema sea el certificado.
     */
    private static function entornoDe(object $factura): string
    {
        $entorno = DB::table('document_numbers as dn')
            ->join('document_series as ds', 'ds.id', '=', 'dn.document_series_id')
            ->where('dn.id', $factura->document_number_id)
            ->value('ds.environment');

        return (string) ($entorno ?? 'production');
    }

    private static function facturaEmitida(string $uuid): object
    {
        $factura = DB::table('invoices')->where('uuid', $uuid)->first();

        if ($factura === null) {
            throw new RuntimeException('No existe esa factura.');
        }

        if ($factura->status === 'draft') {
            throw new RuntimeException(
                'Un borrador no tiene numero, y sin numero no hay comprobante que armar. '
                .'Emita la factura primero.',
            );
        }

        if ($factura->issuer_country_snapshot === null) {
            throw new RuntimeException(
                'Esa factura no congelo el pais del emisor: es anterior a 9.9b y no se puede '
                .'saber con que reglas se emitio.',
            );
        }

        return $factura;
    }

    // -------------------------------------------------------------- avisos

    /**
     * Lo que impide emitir electrónicamente, y se arregla en otra pantalla.
     *
     * @return list<Aviso>
     */
    public static function avisos(): array
    {
        $avisos = [];

        // Sociedades con facturas emitidas y sin ubigeo. SUNAT lo exige y el
        // rechazo que devuelve --un codigo de error numerico-- no lo dice.
        $sinUbigeo = DB::table('legal_entities as le')
            ->join('countries as c', 'c.id', '=', 'le.country_id')
            ->where('c.iso2', 'PE')->where('le.status', 'active')
            ->where(function ($q): void {
                $q->whereNull('le.tax_location_code')->orWhere('le.tax_location_code', '');
            })
            ->pluck('le.code');

        if ($sinUbigeo->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Sin ubigeo: %s. SUNAT lo exige en el comprobante y rechaza sin decir que falta eso.',
                $sinUbigeo->implode(', '),
            ));
        }

        return $avisos;
    }
}
