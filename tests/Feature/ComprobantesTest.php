<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Certificados;
use App\Modules\Core\Services\Correlativos;
use App\Modules\Finance\Emision\Armadores;
use App\Modules\Finance\Services\Comprobantes;
use App\Modules\Finance\Services\Facturas;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use DOMDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El XML firmado del comprobante (iteración 9.9d).
 *
 * ### Lo que fija
 *
 * 1. **Que sale un UBL 2.1 firmado de verdad**, no una cadena con aspecto de
 *    XML: se parsea, se busca la firma y se comprueban las cifras contra la
 *    factura.
 * 2. **Que Greenter no se ve desde fuera** (`DEC-252`). Lo garantiza `deptrac`
 *    con una capa propia; aquí se afirma la otra mitad: el registro por país
 *    devuelve un armador y quien lo pide no nombra ninguna librería.
 * 3. **Que lo firmado no se toca.** Regenerar reemplaza y no borra, y la base
 *    rechaza cualquier intento de editar el XML guardado.
 */
final class ComprobantesTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    private int $sociedadId;

    private int $serieId;

    private int $autorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->autorId = (int) $this->usuarioCon('admin')->id;

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME',
            'client_code' => 'ACME-01', 'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->perfilFiscal($this->paisPE);

        // Con ubigeo y distrito: SUNAT los exige y sin ellos el XML sale, pero
        // lo rechaza el receptor con un codigo que no dice que falta eso.
        $this->sociedadId = $this->entidadLegal([
            'country_id' => $this->paisPE,
            'tax_location_code' => '150101',
            'district' => 'MIRAFLORES',
            'city' => 'LIMA',
            'region' => 'LIMA',
        ]);
        $this->serieId = $this->serieDe($this->sociedadId);

        $this->cargarCertificado();
    }

    // -------------------------------------------------------------- el XML

    /**
     * **La que más importa.** Sale un UBL 2.1 firmado, con las cifras de la
     * factura dentro.
     */
    public function test_sale_un_ubl_firmado_con_las_cifras_de_la_factura(): void
    {
        $factura = $this->facturaEmitida();

        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        $fila = Comprobantes::xml($uuid);
        $doc = new DOMDocument;

        self::assertTrue($doc->loadXML((string) $fila->xml_content), 'es XML valido');
        self::assertSame('Invoice', $doc->documentElement?->localName);
        self::assertStringContainsString('ubl:schema:xsd:Invoice-2', (string) $fila->xml_content);

        // La firma: sin ella esto no es un comprobante, es un informe.
        self::assertGreaterThan(
            0,
            $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length,
            'lleva firma digital',
        );

        // Y las cifras, que es lo que se declara.
        self::assertStringContainsString((string) $factura->issuer_tax_id_snapshot, (string) $fila->xml_content);
        self::assertStringContainsString('1180.00', (string) $fila->xml_content);
    }

    /**
     * El nombre lo arma el sistema de las mismas cifras que van dentro.
     *
     * SUNAT identifica el documento por este nombre dentro del ZIP y lo rechaza
     * **sin decir que el problema es el nombre** si no cuadra con el contenido.
     */
    public function test_el_nombre_del_archivo_es_el_que_exige_sunat(): void
    {
        $factura = $this->facturaEmitida();
        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        self::assertSame(
            sprintf('%s-01-%s-%d.xml', $factura->issuer_tax_id_snapshot, $factura->series, $factura->number),
            (string) Comprobantes::xml($uuid)->name,
        );
    }

    /** El total en letras entra en el XML: SUNAT lo exige (leyenda 1000). */
    public function test_el_importe_en_letras_va_dentro(): void
    {
        $factura = $this->facturaEmitida();
        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        self::assertStringContainsString(
            'MIL CIENTO OCHENTA CON 00/100 SOLES',
            (string) Comprobantes::xml($uuid)->xml_content,
        );
    }

    // ------------------------------------------------- lo firmado no se toca

    /**
     * Regenerar **reemplaza y no borra**.
     *
     * Si esa versión ya se mandó, lo que la administración tiene es la vieja, y
     * perderla es perder la única copia de lo que se declaró.
     */
    public function test_volver_a_armarlo_reemplaza_y_no_borra_el_anterior(): void
    {
        $factura = $this->facturaEmitida();

        $primero = Comprobantes::generar($factura->uuid, $this->autorId);
        $segundo = Comprobantes::generar($factura->uuid, $this->autorId);

        self::assertNotSame($primero, $segundo);
        self::assertSame(2, DB::table('electronic_documents')
            ->where('invoice_id', $factura->id)->count(), 'los dos siguen ahi');
        self::assertSame($segundo, (string) Comprobantes::vigente((int) $factura->id)->uuid);
        self::assertNotNull(DB::table('electronic_documents')->where('uuid', $primero)->value('superseded_at'));
    }

    /** Y la base no deja editar lo que se firmó. */
    public function test_la_base_no_deja_cambiar_el_xml_guardado(): void
    {
        $factura = $this->facturaEmitida();
        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_edoc_inmutable` lo rechaza, y esa es
        // la afirmacion.
        DB::table('electronic_documents')->where('uuid', $uuid)
            ->update(['xml_content' => '<Invoice>manipulado</Invoice>']);
    }

    /** Ni borrarlo. */
    public function test_la_base_no_deja_borrar_un_comprobante_firmado(): void
    {
        $factura = $this->facturaEmitida();
        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        $this->expectException(QueryException::class);

        DB::table('electronic_documents')->where('uuid', $uuid)->delete();
    }

    /**
     * Si alguien lo altera por debajo de los disparadores, **no se entrega**.
     *
     * Es la última red: una restauración a medias o una edición directa en la
     * base dejan un documento que ya no prueba nada, y devolverlo en silencio
     * sería entregarlo como si probara.
     */
    public function test_si_la_huella_no_cuadra_no_se_entrega(): void
    {
        $factura = $this->facturaEmitida();
        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        // Se QUITA el disparador para poder corromper la fila.
        //
        // No es hacer trampa: es reproducir el unico caso en el que esta
        // comprobacion sirve. Mientras el disparador este puesto, la fila no se
        // puede tocar --lo afirma la prueba de al lado--. Lo que se simula aqui
        // es lo que ese disparador NO alcanza: una restauracion a medias, un
        // volcado importado con `--skip-triggers`, o alguien con acceso directo
        // al motor. En cualquiera de los tres, el documento deja de probar lo
        // que dice probar, y entregarlo en silencio seria lo peor que se puede
        // hacer con una prueba fiscal.
        DB::statement('DROP TRIGGER `tg_edoc_inmutable`');
        DB::statement(
            'UPDATE electronic_documents SET sha256 = ? WHERE uuid = ?',
            [str_repeat('a', 64), $uuid],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no cuadra');

        Comprobantes::xml($uuid);
    }

    // -------------------------------------------------------- lo que impide

    /** Un borrador no tiene número, así que no hay comprobante que armar. */
    public function test_un_borrador_no_arma_ningun_comprobante(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Emita la factura primero');

        Comprobantes::generar($uuid, $this->autorId);
    }

    /** Y sin certificado vigente tampoco: firmar exige con qué. */
    public function test_sin_certificado_vigente_lo_dice_con_palabras(): void
    {
        DB::table('signing_certificates')->update([
            'status' => 'revoked', 'revoked_at' => now(),
            'revoked_reason' => 'Retirado por la prueba, a proposito.',
        ]);

        $factura = $this->facturaEmitida();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('certificado de firma vigente');

        Comprobantes::generar($factura->uuid, $this->autorId);
    }

    /**
     * Un país sin armador **lo dice**, en vez de inventar un XML.
     *
     * Es lo que hace que esto sirva para seis países: el que no se sabe emitir
     * se nota, y no produce un documento que ninguna administración acepta.
     */
    public function test_un_pais_sin_armador_lo_dice_en_vez_de_inventar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay forma de emitir');

        Armadores::para('CO');
    }

    /** Y el registro sabe decir para cuáles sí. */
    public function test_el_registro_dice_en_que_paises_sabe_emitir(): void
    {
        self::assertTrue(Armadores::hay('PE'));
        self::assertTrue(Armadores::hay('pe'), 'no distingue mayusculas');
        self::assertContains('PE', Armadores::paises());
    }

    // ------------------------------------------------------------ pantalla

    public function test_desde_la_pantalla_se_arma_y_se_descarga(): void
    {
        $factura = $this->facturaEmitida();
        $admin = $this->usuarioCon('admin');

        $this->actingAs($admin)
            ->post(route('facturas.comprobante', ['uuid' => $factura->uuid]))
            ->assertRedirect();

        $documento = Comprobantes::vigente((int) $factura->id);
        self::assertNotNull($documento);

        $respuesta = $this->actingAs($admin)->get(route('facturas.comprobante.descargar', [
            'uuid' => $factura->uuid, 'documento' => $documento->uuid,
        ]));

        $respuesta->assertOk();
        $respuesta->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        self::assertStringContainsString(
            'attachment; filename="'.$documento->name.'"',
            (string) $respuesta->headers->get('Content-Disposition'),
        );
    }

    /**
     * Quien no tiene finanzas no descarga el comprobante, y no puede armarlo.
     *
     * Las dos rutas piden permisos DISTINTOS a propósito —descargar es
     * `finance.view`, armar es `finance.invoice.issue`— porque el XML es lo que
     * se le manda al cliente y al contador, y eso lo hace más gente que la que
     * emite. Hoy el rol de finanzas tiene los dos, así que la separación aún no
     * se puede afirmar con un rol real: lo que se afirma es que **ninguna de las
     * dos está abierta**, y `verificar-muro.py` vigila que cada una siga
     * declarando el suyo.
     */
    public function test_sin_permisos_de_finanzas_no_se_arma_ni_se_descarga(): void
    {
        $factura = $this->facturaEmitida();
        Comprobantes::generar($factura->uuid, $this->autorId);
        $documento = Comprobantes::vigente((int) $factura->id);

        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)->get(route('facturas.comprobante.descargar', [
            'uuid' => $factura->uuid, 'documento' => $documento->uuid,
        ]))->assertForbidden();

        $this->actingAs($revisor)
            ->post(route('facturas.comprobante', ['uuid' => $factura->uuid]))
            ->assertForbidden();

        // Y quien si las tiene, hace las dos.
        $finanzas = $this->usuarioCon('finance');
        $this->actingAs($finanzas)->get(route('facturas.comprobante.descargar', [
            'uuid' => $factura->uuid, 'documento' => $documento->uuid,
        ]))->assertOk();
    }

    /**
     * La fecha del comprobante es el día de la factura, **no el de UTC**.
     *
     * Salió al generar el primer XML de verdad: `issue_date` es «el día en la
     * zona de la sociedad» (`2.3 §8`), y construirlo en UTC hacía que Greenter
     * lo escribiera como el día ANTERIOR en hora de Lima. Un comprobante con
     * fecha de ayer no es un detalle de formato.
     */
    public function test_la_fecha_del_comprobante_es_la_de_la_factura(): void
    {
        $factura = $this->facturaEmitida();
        $uuid = Comprobantes::generar($factura->uuid, $this->autorId);

        self::assertStringContainsString(
            '<cbc:IssueDate>'.$factura->issue_date.'</cbc:IssueDate>',
            (string) Comprobantes::xml($uuid)->xml_content,
        );
    }

    // ----------------------------------------------------------- ayudantes

    private function campanaFacturable(float $importe = 12000.0): int
    {
        return $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'completed',
            'billing_legal_entity_id' => $this->sociedadId,
            'currency_code' => 'PEN',
            'revenue_amount' => $importe,
            'is_gratis' => 0,
        ]);
    }

    private function perfilFiscal(int $paisId): int
    {
        return (int) DB::table('client_tax_profiles')->insertGetId([
            'client_organization_id' => $this->clienteId,
            'country_id' => $paisId,
            'legal_name' => 'ACME S.A.',
            'tax_id_type' => 'RUC',
            'tax_id_number' => (string) random_int(20000000000, 20999999999),
            'address_line1' => 'Av. Demo 100',
            'city' => 'Lima',
            'payment_term_days' => 30,
            'valid_from' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function serieDe(int $sociedadId, string $serie = 'F001'): int
    {
        $tipo = (int) DB::table('document_types')
            ->where('country_id', $this->paisPE)->where('code', 'invoice')->value('id');

        return Correlativos::guardarSerie(null, [
            'legal_entity_id' => $sociedadId,
            'document_type_id' => $tipo,
            'series' => $serie,
            'next_number' => 1,
            'environment' => 'production',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * Una factura de 1 000 + 180 = 1 180, para que las cifras del XML se puedan
     * leer a ojo en la prueba.
     *
     * NO se anade una linea a mano: `borrador()` ya crea la de la campana, y
     * anadir otra daba un total distinto del que las aserciones esperaban --lo
     * cual, dicho sea, es exactamente lo que la prueba tenia que enseNar--.
     */
    private function facturaEmitida(): object
    {
        $uuid = Facturas::borrador($this->campanaFacturable(1000.0));

        Facturas::emitir($uuid, $this->serieId, $this->autorId);

        return DB::table('invoices')->where('uuid', $uuid)->first();
    }

    /**
     * Un certificado de verdad, hecho al vuelo.
     *
     * Con OpenSSL en la propia prueba y no un `.pfx` en el repositorio: un
     * certificado guardado caduca, y una prueba que empieza a fallar sola el día
     * que caduca es una prueba que se acaba borrando.
     */
    private function cargarCertificado(): void
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $ruc = (string) DB::table('legal_entities')->where('id', $this->sociedadId)->value('tax_id_number');
        $csr = openssl_csr_new(
            ['countryName' => 'PE', 'organizationName' => 'Sociedad de prueba SAC', 'commonName' => $ruc],
            $clave,
            ['digest_alg' => 'sha256'],
        );
        $x509 = openssl_csr_sign($csr, null, $clave, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pemCert);
        openssl_pkey_export($clave, $pemClave);

        Certificados::cargar($this->sociedadId, 'production', $pemClave.$pemCert, null, $this->autorId);
    }
}
