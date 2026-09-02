<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Core\Services\Certificados;
use App\Modules\Core\Services\Integraciones;
use App\Modules\Finance\Emision\Armadores;
use App\Modules\Finance\Emision\Comprobante;
use App\Modules\Finance\Emision\CredencialesDeEnvio;
use App\Modules\Finance\Emision\Enviadores;
use App\Modules\Finance\Emision\LineaDeComprobante;
use App\Modules\Finance\Emision\Parte;
use App\Modules\Finance\Emision\RespuestaDeEnvio;
use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Config\Instalacion;
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

    /** La respuesta firmada de la administracion (9.9e). */
    public const CDR = 'cdr';

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

    /**
     * Entrega el comprobante a la administración y guarda lo que conteste (9.9e).
     *
     * ### Qué se guarda, y por qué tres sitios
     *
     * - **`document_submissions`** — el intento: qué se mandó, cuándo, quién,
     *   contra qué conexión, cuánto tardó y qué contestaron. Es lo que explica
     *   qué pasó, y no se pisa: reintentar añade una fila.
     * - **`electronic_documents kind='cdr'`** — la respuesta firmada de la
     *   administración, que es la prueba de que el comprobante existe para
     *   ellos. Va donde va el XML y por los mismos motivos (`DEC-270`).
     * - **`invoices.external_status`** — cómo está AHORA, que es lo que la
     *   pantalla enseña sin abrir nada.
     *
     * ### No lanza por un rechazo
     *
     * Un rechazo es una respuesta y hay que guardarla. Lo que sí lanza es lo que
     * impide siquiera intentarlo: sin XML armado, sin conexión activa o sin
     * clave, no hay nada que mandar y decirlo es más útil que un intento
     * fallido más en el registro.
     */
    public static function enviar(string $facturaUuid, int $usuarioId): RespuestaDeEnvio
    {
        $factura = self::facturaEmitida($facturaUuid);
        $documento = self::vigente((int) $factura->id);

        if ($documento === null) {
            throw new RuntimeException(
                'Esa factura todavia no tiene comprobante armado: armelo antes de mandarlo.',
            );
        }

        $entorno = self::entornoDe($factura);
        $credenciales = self::credenciales($factura, $entorno);
        $enviador = Enviadores::para((string) $factura->issuer_country_snapshot);

        $arranque = microtime(true);
        $respuesta = $enviador->envia(
            (string) $documento->name,
            (string) self::xml((string) $documento->uuid)->xml_content,
            $credenciales,
        );
        $tardo = (int) round((microtime(true) - $arranque) * 1000);

        self::anotarEnvio($factura, $documento, $credenciales, $respuesta, $tardo, $usuarioId);

        return $respuesta;
    }

    /**
     * Con qué conexión se habla. **Aquí es donde se descubre lo que falta.**
     *
     * Cada `throw` de este método es un ajuste concreto que alguien tiene que
     * ir a hacer, y por eso lo dice con esas palabras en vez de dejar que la
     * llamada falle luego con un error del otro lado.
     */
    private static function credenciales(object $factura, string $entorno): CredencialesDeEnvio
    {
        // 9.22a: la eleccion de conexion pasa por LA PUERTA, que comprueba
        // antes que nada si esta instalacion puede hablar con ese entorno
        // (`DEC-029`). Antes esta consulta vivia aqui, y la barrera habria
        // habido que acordarse de escribirla tambien en el correo y en los
        // cobros --que es como se escriben las barreras que un dia faltan--.
        $conexion = Integraciones::conexionParaUsar(
            'invoicing',
            (int) $factura->legal_entity_id,
            $entorno,
        );

        if (trim((string) $conexion->username) === '') {
            throw new RuntimeException(
                'Esa conexion no tiene usuario secundario. Sin el, la administracion no sabe quien llama.',
            );
        }

        $clave = Integraciones::secreto((int) $conexion->id, 'password');

        if ($clave === null || trim($clave) === '') {
            throw new RuntimeException(
                'Esa conexion no tiene contrasena guardada: cargue la del usuario secundario en Integraciones.',
            );
        }

        $url = Integraciones::urlDe((int) $conexion->id);

        if ($url === null || trim($url) === '') {
            throw new RuntimeException(
                'Esa conexion no sabe a donde llamar: ni ella ni el catalogo del proveedor declaran direccion.',
            );
        }

        return new CredencialesDeEnvio(
            url: $url,
            identificadorEmisor: (string) $factura->issuer_tax_id_snapshot,
            usuario: (string) $conexion->username,
            clave: $clave,
            entorno: $entorno,
            conexion: (string) $conexion->name,
        );
    }

    /**
     * Anota el intento, guarda el CDR y actualiza el estado. **En una sola
     * transacción**: un intento anotado sin su CDR, o un estado que dice
     * «aceptada» sin la prueba, son peores que no haberlo guardado.
     */
    private static function anotarEnvio(
        object $factura,
        object $documento,
        CredencialesDeEnvio $credenciales,
        RespuestaDeEnvio $respuesta,
        int $tardo,
        int $usuarioId,
    ): void {
        DB::transaction(function () use (
            $factura, $documento, $credenciales, $respuesta, $tardo, $usuarioId
        ): void {
            $intento = 1 + (int) DB::table('document_submissions')
                ->where('invoice_id', $factura->id)->max('attempt_number');

            DB::table('document_submissions')->insert([
                'uuid' => (string) Str::uuid(),
                'invoice_id' => (int) $factura->id,
                'electronic_document_id' => (int) $documento->id,
                'attempt_number' => $intento,
                'outcome' => $respuesta->estado,
                'response_code' => $respuesta->codigo,
                'response_message' => $respuesta->descripcion === '' ? null : $respuesta->descripcion,
                'notes_count' => count($respuesta->notas),
                'connection_snapshot' => mb_substr($credenciales->conexion, 0, 60),
                'environment' => $credenciales->entorno,
                'duration_ms' => $tardo,
                'sent_at' => now(),
                'sent_by_user_id' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($respuesta->cdr !== null) {
                self::guardarCdr($factura, $respuesta, $usuarioId);
            }

            DB::table('invoices')->where('id', $factura->id)->update([
                'external_status' => $respuesta->estado,
                'integration_connection_snapshot' => mb_substr($credenciales->conexion, 0, 60),
                'updated_at' => now(),
            ]);
        });

        // El codigo y la descripcion SI entran en la bitacora --son lo que hay
        // que poder citar-- y las credenciales NO (`BR-SEC-001`).
        Bitacora::registrar(
            accion: 'invoice.submitted',
            tipoEntidad: 'invoice',
            idEntidad: (int) $factura->id,
            cambios: [
                'resultado' => ['antes' => null, 'despues' => $respuesta->estado],
                'respuesta' => ['antes' => null,
                    'despues' => trim(($respuesta->codigo ?? '-').' '.$respuesta->descripcion)],
            ],
        );
    }

    /**
     * El CDR, guardado como un documento electrónico más.
     *
     * Va **comprimido tal cual llegó**: es lo que la administración firmó, y
     * descomprimirlo para guardarlo bonito sería guardar otra cosa.
     */
    private static function guardarCdr(object $factura, RespuestaDeEnvio $respuesta, int $usuarioId): void
    {
        DB::table('electronic_documents')
            ->where('invoice_id', $factura->id)->where('kind', self::CDR)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now(), 'updated_at' => now()]);

        DB::table('electronic_documents')->insert([
            'uuid' => (string) Str::uuid(),
            'invoice_id' => (int) $factura->id,
            'kind' => self::CDR,
            'name' => (string) ($respuesta->nombreCdr ?? 'cdr.zip'),
            'xml_content' => (string) $respuesta->cdr,
            'sha256' => hash('sha256', (string) $respuesta->cdr),
            'size_bytes' => strlen((string) $respuesta->cdr),
            'generated_at' => now(),
            'generated_by_user_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Los intentos de una factura, el último primero.
     *
     * @return Collection<int, \stdClass>
     */
    public static function intentos(int $facturaId): Collection
    {
        return DB::table('document_submissions as ds')
            ->leftJoin('users as u', 'u.id', '=', 'ds.sent_by_user_id')
            ->where('ds.invoice_id', $facturaId)
            ->orderByDesc('ds.attempt_number')
            ->get(['ds.uuid', 'ds.attempt_number', 'ds.outcome', 'ds.response_code',
                'ds.response_message', 'ds.notes_count', 'ds.connection_snapshot',
                'ds.environment', 'ds.duration_ms', 'ds.sent_at', 'u.name as autor']);
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
    /**
     * `null` si esta factura se puede mandar desde AQUÍ; el motivo si no (9.22a).
     *
     * Existe para que la pantalla lo diga **antes** del botón y no después del
     * clic. Es la misma idea que `porQueNoPuede()` en `9.9e` y que `Esquema` en
     * `9.17j`, y aquí importa más que en ninguno de los dos: los otros dos
     * avisan de algo que va a fallar, y éste avisa de algo que va a **funcionar
     * cuando no debía**.
     *
     * No mira si falta la contraseña ni el certificado. Eso son datos que faltan
     * y su sitio es el error al intentarlo; esto contesta a otra pregunta —«¿es
     * ésta la máquina desde la que se manda?»— que no depende de cómo esté
     * configurada la conexión, y que por eso se puede contestar sin tocar
     * ninguna credencial.
     */
    public static function porQueNoSePuedeMandar(object $factura): ?string
    {
        $entorno = self::entornoDe($factura);

        if (($motivo = Instalacion::porQueNoPuedeUsar($entorno)) !== null) {
            return $motivo;
        }

        $pais = (string) ($factura->issuer_country_snapshot ?? '');

        if ($pais === '' || !Enviadores::hay($pais)) {
            return $pais === ''
                ? 'Esta factura no tiene congelado el país del emisor, así que no se sabe a qué administración va.'
                : sprintf('No hay forma de entregar un comprobante electrónico en %s.', $pais);
        }

        return Enviadores::para($pais)->porQueNoPuede();
    }

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
