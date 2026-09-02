<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Campaign\Services\Campanas;
use App\Modules\Client\Services\PerfilesFiscales;
use App\Modules\Core\Services\Correlativos;
use App\Modules\Core\Services\Impuestos;
use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Database\Vigencia;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La factura que sale de una campaña (9.9b).
 *
 * ### Tres cosas que no se hacen aquí, y por qué
 *
 * **El número no se pide al crear el borrador.** Se pide al emitir. Un
 * correlativo es un recurso escaso y auditado: gastarlo al empezar a escribir
 * significa que cada borrador descartado deja un hueco ante SUNAT. Por eso
 * `series` y `number` admiten `NULL` desde `9.9b` y el borrador no los lleva.
 *
 * **El régimen no se teclea: se deduce, y sin nombrar ningún país.** `DEC-047`
 * dice que al cliente domiciliado donde se emite se le grava, y al de fuera la
 * operación es exportación de servicios. La comparación es entre el país del
 * emisor y el del receptor —los dos congelados en el documento—, así que la
 * misma línea de código sirve para Perú, Colombia y España (`DEC-190`).
 *
 * **La aritmética la hace la base.** No hay `bcmath` en el servidor del cliente,
 * y `float` no es aceptable para un importe que va en un comprobante fiscal. Se
 * calcula con `CAST(... AS DECIMAL(28,12))` y se redondea en SQL, que es lo que
 * este proyecto hace desde `9.3`.
 *
 * ### Lo que se congela en el instante de emitir
 *
 * Nombre, identificador fiscal, domicilio y **país** de las dos partes; la tasa
 * y su porcentaje; el número y de qué serie salió. A partir de ahí
 * `tg_invoice_emision` no deja cambiar nada de eso: una factura emitida no se
 * corrige, se anula y se emite otra.
 */
final class Facturas
{
    /** @var array<string, string> */
    public const ESTADOS = [
        'draft' => 'Borrador — todavía no existe para nadie',
        'issued' => 'Emitida',
        'sent' => 'Enviada a la administración',
        'partially_paid' => 'Cobrada en parte',
        'paid' => 'Cobrada',
        'rejected' => 'Rechazada',
        'voided' => 'Anulada',
    ];

    /** @var array<string, string> */
    public const REGIMENES = [
        'gravado' => 'Gravado — lleva el impuesto de venta del país que emite',
        'exportacion' => 'Exportación de servicios — sin impuesto',
        'exonerado' => 'Exonerado',
        'inafecto' => 'Inafecto',
    ];

    /** Lo mínimo que explica una anulación. «Error» no explica nada. */
    private const MOTIVO_MINIMO = 10;

    // ------------------------------------------------------------- lecturas

    /**
     * @param array<string, mixed> $filtros
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public static function listado(array $filtros = []): LengthAwarePaginator
    {
        return DB::table('invoices as i')
            ->leftJoin('client_organizations as co', 'co.id', '=', 'i.client_organization_id')
            ->leftJoin('campaigns as ca', 'ca.id', '=', 'i.campaign_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'i.legal_entity_id')
            ->when(($filtros['estado'] ?? '') !== '', fn ($q) => $q->where('i.status', $filtros['estado']))
            ->when(($filtros['campana'] ?? 0) > 0, fn ($q) => $q->where('i.campaign_id', $filtros['campana']))
            ->orderByDesc('i.id')
            ->paginate(25, ['i.id', 'i.uuid', 'i.status', 'i.document_type', 'i.series', 'i.number',
                'i.issue_date', 'i.due_date', 'i.currency_code', 'i.tax_regime',
                'i.subtotal_amount', 'i.tax_amount', 'i.total_amount',
                'i.receiver_legal_name_snapshot', 'co.commercial_name as cliente',
                'ca.code as campana_codigo', 'ca.name as campana', 'le.code as sociedad']);
    }

    public static function ver(string $uuid): ?object
    {
        return DB::table('invoices as i')
            ->leftJoin('client_organizations as co', 'co.id', '=', 'i.client_organization_id')
            ->leftJoin('campaigns as ca', 'ca.id', '=', 'i.campaign_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'i.legal_entity_id')
            ->leftJoin('document_numbers as dn', 'dn.id', '=', 'i.document_number_id')
            ->where('i.uuid', $uuid)
            ->first(['i.*', 'co.commercial_name as cliente', 'ca.code as campana_codigo',
                'ca.name as campana', 'le.code as sociedad', 'le.legal_name as sociedad_nombre',
                'dn.full_number']);
    }

    /** @return Collection<int, \stdClass> */
    public static function lineas(int $facturaId): Collection
    {
        return DB::table('invoice_lines')->where('invoice_id', $facturaId)
            ->orderBy('line_number')->get();
    }

    /**
     * Las series que puede usar la sociedad que emite este documento.
     *
     * @return Collection<int, \stdClass>
     */
    public static function seriesDe(int $sociedadId): Collection
    {
        return DB::table('document_series as ds')
            ->join('document_types as dt', 'dt.id', '=', 'ds.document_type_id')
            ->where('ds.legal_entity_id', $sociedadId)
            ->where('ds.is_active', 1)
            ->orderByDesc('ds.is_default')->orderBy('dt.sort_order')->orderBy('ds.series')
            ->get(['ds.id', 'ds.series', 'ds.next_number', 'ds.environment', 'ds.is_default',
                'dt.code as tipo_codigo', 'dt.name as tipo', 'dt.number_length',
                'dt.requires_customer_tax_id']);
    }

    // ------------------------------------------------------------- borrador

    /**
     * Abre el borrador de la factura de una campaña.
     *
     * No numera nada y no congela nada todavía: un borrador es un papel que
     * todavía se puede romper. Lo que sí calcula es el importe, porque es lo que
     * hay que poder mirar antes de decidir emitirlo.
     */
    public static function borrador(int $campanaId): string
    {
        $campana = Campanas::facturable($campanaId);

        if ($campana === null) {
            throw new RuntimeException('No existe esa campana.');
        }

        if ($campana->billing_legal_entity_id === null) {
            throw new RuntimeException(
                'Esa campana no dice que sociedad la factura: asignesela antes de emitir.',
            );
        }

        $yaHay = DB::table('invoices')->where('campaign_id', $campanaId)
            ->where('status', 'draft')->value('uuid');

        if ($yaHay !== null) {
            throw new RuntimeException('Esa campana ya tiene un borrador abierto: termine ese antes de abrir otro.');
        }

        $emisor = self::sociedad((int) $campana->billing_legal_entity_id);
        $receptor = self::receptor((int) $campana->client_organization_id);

        // El dia lo pone el reloj de la sociedad que emite, no el del servidor.
        // Una factura de fin de mes emitida a las 23:40 de Lima cae en el
        // periodo tributario siguiente si se fecha en UTC.
        $dia = Vigencia::fecha(now()->setTimezone((string) $emisor->timezone)->toDateString());

        $regimen = $receptor->iso2 === $emisor->iso2 ? 'gravado' : 'exportacion';
        $tasa = $regimen === 'gravado'
            ? Impuestos::deVenta((int) $emisor->country_id, $dia)
            : null;

        if ($regimen === 'gravado' && $tasa === null) {
            throw new RuntimeException(sprintf(
                'No hay impuesto de venta vigente para %s el %s: declarelo en Impuestos antes de facturar, '
                .'o la factura saldria en cero.',
                (string) $emisor->pais, $dia,
            ));
        }

        $subtotal = (string) $campana->revenue_amount;
        $porcentaje = $tasa === null ? '0' : (string) $tasa->rate;
        $impuesto = self::impuestoDe($subtotal, $porcentaje);

        $uuid = (string) Str::uuid();

        DB::transaction(static function () use (
            $uuid, $campana, $emisor, $receptor, $dia, $regimen, $tasa,
            $subtotal, $porcentaje, $impuesto,
        ): void {
            $id = (int) DB::table('invoices')->insertGetId([
                'uuid' => $uuid,
                'legal_entity_id' => (int) $campana->billing_legal_entity_id,
                'client_organization_id' => (int) $campana->client_organization_id,
                'client_tax_profile_id' => (int) $receptor->id,
                'campaign_id' => (int) $campana->id,
                'document_type' => 'invoice',
                'issue_date' => $dia,
                'due_date' => Vigencia::masDias($dia, (int) $receptor->payment_term_days),
                'currency_code' => (string) $campana->currency_code,
                'tax_regime' => $regimen,
                'tax_rate_id' => $tasa?->id,
                'tax_rate_snapshot' => $tasa === null ? null : (string) $tasa->rate,
                'subtotal_amount' => $subtotal,
                'tax_amount' => $impuesto,
                'total_amount' => self::sumar($subtotal, $impuesto),
                'status' => 'draft',
                // El borrador ya lleva los datos de las dos partes para que se
                // puedan LEER antes de emitir. Se vuelven a copiar al emitir:
                // entre hoy y entonces el cliente puede cambiar de domicilio, y
                // lo que vale es lo que era el dia del documento.
                'issuer_legal_name_snapshot' => (string) $emisor->legal_name,
                'issuer_tax_id_snapshot' => (string) $emisor->tax_id_number,
                'issuer_address_snapshot' => self::domicilio($emisor),
                'issuer_country_snapshot' => (string) $emisor->iso2,
                // 9.9f (`T-87`): la LOCALIDAD tambien. `9.9b` congelo el nombre,
                // el identificador y el domicilio, pero no el ubigeo ni el
                // distrito --y son justo los campos que el comprobante
                // electronico lleva dentro--. Sin esto, regenerar el XML de una
                // factura vieja despues de que la sociedad se mude produce un
                // documento DISTINTO del que se emitio, y los dos van firmados.
                'issuer_tax_location_snapshot' => $emisor->tax_location_code,
                'issuer_district_snapshot' => $emisor->district,
                'issuer_province_snapshot' => $emisor->city,
                'issuer_region_snapshot' => $emisor->region,
                'issuer_establishment_snapshot' => $emisor->establishment_code,
                'receiver_legal_name_snapshot' => (string) $receptor->legal_name,
                'receiver_tax_id_snapshot' => (string) $receptor->tax_id_number,
                'receiver_address_snapshot' => self::domicilio($receptor),
                'receiver_country_snapshot' => (string) $receptor->iso2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('invoice_lines')->insert([
                'invoice_id' => $id,
                'line_number' => 1,
                'description' => mb_substr(sprintf(
                    'Servicios de marketing con creadores - campana %s: %s',
                    (string) $campana->code, (string) $campana->name,
                ), 0, 300),
                'quantity' => '1',
                'unit_price' => $subtotal,
                'line_subtotal' => $subtotal,
                'tax_rate' => $porcentaje,
                'line_tax' => $impuesto,
                'line_total' => self::sumar($subtotal, $impuesto),
            ]);

            Bitacora::registrar(
                accion: 'invoice.drafted',
                tipoEntidad: 'invoice',
                idEntidad: $id,
                cambios: [
                    'campana' => ['antes' => null, 'despues' => (string) $campana->code],
                    'regimen' => ['antes' => null, 'despues' => $regimen],
                    'total' => ['antes' => null, 'despues' => self::sumar($subtotal, $impuesto)],
                ],
            );
        });

        return $uuid;
    }

    /**
     * Guarda una línea del borrador y rehace la cabecera.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarLinea(string $uuid, ?int $lineaId, array $datos): void
    {
        DB::transaction(static function () use ($uuid, $lineaId, $datos): void {
            $factura = self::borradorBloqueado($uuid);
            $cantidad = (string) $datos['quantity'];
            $precio = (string) $datos['unit_price'];
            $subtotal = self::multiplicar($cantidad, $precio);
            $porcentaje = (string) ($factura->tax_rate_snapshot ?? '0');
            $impuesto = $factura->tax_regime === 'gravado' ? self::impuestoDe($subtotal, $porcentaje) : '0';

            $campos = [
                'description' => mb_substr(trim((string) $datos['description']), 0, 300),
                'quantity' => $cantidad,
                'unit_price' => $precio,
                'line_subtotal' => $subtotal,
                'tax_rate' => $factura->tax_regime === 'gravado' ? $porcentaje : '0',
                'line_tax' => $impuesto,
                'line_total' => self::sumar($subtotal, $impuesto),
            ];

            if ($lineaId === null) {
                $siguiente = (int) DB::table('invoice_lines')
                    ->where('invoice_id', $factura->id)->max('line_number');
                DB::table('invoice_lines')->insert(
                    $campos + ['invoice_id' => (int) $factura->id, 'line_number' => $siguiente + 1],
                );
            } else {
                DB::table('invoice_lines')
                    ->where('invoice_id', $factura->id)->where('id', $lineaId)
                    ->update($campos);
            }

            self::recalcular((int) $factura->id);
        });
    }

    public static function borrarLinea(string $uuid, int $lineaId): void
    {
        DB::transaction(static function () use ($uuid, $lineaId): void {
            $factura = self::borradorBloqueado($uuid);

            if (DB::table('invoice_lines')->where('invoice_id', $factura->id)->count() <= 1) {
                throw new RuntimeException('Una factura sin lineas no dice que se cobra: deje al menos una.');
            }

            DB::table('invoice_lines')
                ->where('invoice_id', $factura->id)->where('id', $lineaId)->delete();

            self::recalcular((int) $factura->id);
        });
    }

    /** Descarta un borrador. Un papel que nunca salió no se anula: se rompe. */
    public static function descartar(string $uuid): void
    {
        DB::transaction(static function () use ($uuid): void {
            $factura = self::borradorBloqueado($uuid);

            DB::table('invoice_lines')->where('invoice_id', $factura->id)->delete();
            DB::table('invoices')->where('id', $factura->id)->delete();

            Bitacora::registrar(
                accion: 'invoice.discarded',
                tipoEntidad: 'invoice',
                idEntidad: (int) $factura->id,
                cambios: ['estado' => ['antes' => 'draft', 'despues' => 'descartado']],
            );
        });
    }

    /** La cabecera es la suma de sus líneas, siempre. */
    public static function recalcular(int $facturaId): void
    {
        $suma = DB::table('invoice_lines')->where('invoice_id', $facturaId)
            ->selectRaw('COALESCE(SUM(line_subtotal),0) AS sub, COALESCE(SUM(line_tax),0) AS imp')
            ->first();

        $sub = (string) ($suma->sub ?? '0');
        $imp = (string) ($suma->imp ?? '0');

        DB::table('invoices')->where('id', $facturaId)->update([
            'subtotal_amount' => $sub,
            'tax_amount' => $imp,
            'total_amount' => self::sumar($sub, $imp),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------- emisión

    /**
     * Emite: pide el número, congela las dos partes y cierra el documento.
     *
     * Todo dentro de una transacción, y la factura **bloqueada**: dos personas
     * pulsando «emitir» a la vez sobre el mismo borrador reservarían dos números
     * y sólo uno quedaría escrito. El otro sería un hueco sin explicación.
     */
    public static function emitir(string $uuid, int $serieId, int $usuarioId): string
    {
        return DB::transaction(static function () use ($uuid, $serieId, $usuarioId): string {
            $factura = self::borradorBloqueado($uuid);
            $emisor = self::sociedad((int) $factura->legal_entity_id);
            $receptor = self::receptor((int) $factura->client_organization_id);

            $serie = DB::table('document_series as ds')
                ->join('document_types as dt', 'dt.id', '=', 'ds.document_type_id')
                ->where('ds.id', $serieId)
                ->first(['ds.id', 'ds.legal_entity_id', 'ds.is_active', 'ds.series',
                    'dt.code as tipo_codigo', 'dt.requires_customer_tax_id']);

            if ($serie === null) {
                throw new RuntimeException('No existe esa serie.');
            }

            // La serie es de la sociedad que emite (`BR-LE-008`). Sin esta
            // comprobacion se podria numerar una factura de CTS-PE con el
            // contador de CTS-CO, y el libro de las dos quedaria mintiendo.
            if ((int) $serie->legal_entity_id !== (int) $factura->legal_entity_id) {
                throw new RuntimeException('Esa serie es de otra sociedad: no puede numerar esta factura.');
            }

            if ((bool) $serie->requires_customer_tax_id && trim((string) $receptor->tax_id_number) === '') {
                throw new RuntimeException(
                    'Ese tipo de comprobante exige el identificador fiscal del cliente, y no consta.',
                );
            }

            // Se recalcula justo antes: entre abrir el borrador y emitirlo
            // alguien pudo tocar una linea, y lo que vale es lo que se imprime.
            self::recalcular((int) $factura->id);
            $factura = self::porId((int) $factura->id);

            $numero = Correlativos::reservar($serieId, $usuarioId);

            DB::table('invoices')->where('id', $factura->id)->update([
                'document_type' => (string) $serie->tipo_codigo,
                'series' => (string) $serie->series,
                'number' => $numero->numero,
                'document_number_id' => $numero->id,
                'status' => 'issued',
                'issued_at' => now(),
                'issued_by_user_id' => $usuarioId,
                // Se vuelven a copiar AHORA. El borrador pudo abrirse hace una
                // semana y el cliente haber cambiado de domicilio: lo que vale
                // es lo que era el dia en que el documento existio.
                'issuer_legal_name_snapshot' => (string) $emisor->legal_name,
                'issuer_tax_id_snapshot' => (string) $emisor->tax_id_number,
                'issuer_address_snapshot' => self::domicilio($emisor),
                'issuer_country_snapshot' => (string) $emisor->iso2,
                // 9.9f (`T-87`): la LOCALIDAD tambien se vuelve a copiar aqui.
                // El borrador ya la copio al abrirse, pero entre abrirlo y
                // emitirlo la sociedad pudo mudarse, y lo que vale es lo que
                // era el dia en que el documento EXISTIO ante la
                // administracion, no el dia en que alguien empezo a escribirlo.
                'issuer_tax_location_snapshot' => $emisor->tax_location_code,
                'issuer_district_snapshot' => $emisor->district,
                'issuer_province_snapshot' => $emisor->city,
                'issuer_region_snapshot' => $emisor->region,
                'issuer_establishment_snapshot' => $emisor->establishment_code,
                'receiver_legal_name_snapshot' => (string) $receptor->legal_name,
                'receiver_tax_id_snapshot' => (string) $receptor->tax_id_number,
                'receiver_address_snapshot' => self::domicilio($receptor),
                'receiver_country_snapshot' => (string) $receptor->iso2,
                'client_tax_profile_id' => (int) $receptor->id,
                'updated_at' => now(),
            ]);

            Correlativos::usar((int) $numero->id, 'invoice', (int) $factura->id);

            Bitacora::registrar(
                accion: 'invoice.issued',
                tipoEntidad: 'invoice',
                idEntidad: (int) $factura->id,
                cambios: [
                    'estado' => ['antes' => 'draft', 'despues' => 'issued'],
                    'numero' => ['antes' => null, 'despues' => (string) $numero->completo],
                    'total' => ['antes' => null, 'despues' => (string) $factura->total_amount],
                ],
            );

            return (string) $numero->completo;
        });
    }

    /**
     * Anula un comprobante ya emitido, con el motivo escrito.
     *
     * El número **no se devuelve** a la serie: un comprobante anulado sigue
     * existiendo ante la administración y sigue ocupando su correlativo. Devolver
     * el número sería emitir dos documentos con el mismo, que es lo que `9.12`
     * existe para impedir.
     */
    public static function anular(string $uuid, string $motivo): void
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new RuntimeException(
                'El motivo tiene que explicar que paso: una anulacion muda no se puede defender.',
            );
        }

        DB::transaction(static function () use ($uuid, $motivo): void {
            $factura = DB::table('invoices')->where('uuid', $uuid)->lockForUpdate()
                ->first(['id', 'status']);

            if ($factura === null) {
                throw new RuntimeException('No existe esa factura.');
            }

            if ($factura->status === 'draft') {
                throw new RuntimeException('Un borrador no se anula: se descarta, porque nunca existio fuera.');
            }

            if ($factura->status === 'voided') {
                throw new RuntimeException('Esa factura ya estaba anulada.');
            }

            if (in_array($factura->status, ['paid', 'partially_paid'], true)) {
                throw new RuntimeException(
                    'Esa factura tiene cobros registrados: extorne el cobro antes de anularla.',
                );
            }

            DB::table('invoices')->where('id', $factura->id)->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => mb_substr($motivo, 0, 255),
                'updated_at' => now(),
            ]);

            Bitacora::registrar(
                accion: 'invoice.voided',
                tipoEntidad: 'invoice',
                idEntidad: (int) $factura->id,
                cambios: [
                    'estado' => ['antes' => (string) $factura->status, 'despues' => 'voided'],
                    'motivo' => ['antes' => null, 'despues' => mb_substr($motivo, 0, 255)],
                ],
            );
        });
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        if (!Schema::hasTable('invoices')) {
            return [];
        }

        $avisos = [];

        // Campanas terminadas, con importe y sin comprobante. Es dinero
        // trabajado y no cobrado, que es la clase de cosa que se descubre
        // cuadrando el ano.
        $sinFacturar = DB::table('campaigns as c')
            ->whereIn('c.status', ['completed', 'closed'])
            ->where('c.revenue_amount', '>', 0)
            ->where('c.is_gratis', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('invoices as i')
                ->whereColumn('i.campaign_id', 'c.id')
                ->where('i.status', '<>', 'voided'))
            ->count();

        if ($sinFacturar > 0) {
            $avisos[] = Aviso::rojo(sprintf(
                '%d campaña(s) terminadas con importe y sin comprobante emitido.',
                $sinFacturar,
            ));
        }

        $borradores = DB::table('invoices')->where('status', 'draft')->count();

        if ($borradores > 0) {
            $avisos[] = Aviso::ambar(sprintf(
                '%d borrador(es) sin emitir. Un borrador no existe para nadie: no numera y no se cobra.',
                $borradores,
            ));
        }

        return $avisos;
    }

    // ------------------------------------------------------------- privados

    /** El borrador, bloqueado, o una frase que dice por qué no se puede. */
    private static function borradorBloqueado(string $uuid): object
    {
        $factura = DB::table('invoices')->where('uuid', $uuid)->lockForUpdate()
            ->first(['id', 'status', 'legal_entity_id', 'client_organization_id',
                'tax_regime', 'tax_rate_snapshot', 'total_amount']);

        if ($factura === null) {
            throw new RuntimeException('No existe esa factura.');
        }

        if ($factura->status !== 'draft') {
            throw new RuntimeException(
                'Esa factura ya esta emitida: no se corrige, se anula y se emite otra.',
            );
        }

        return $factura;
    }

    private static function porId(int $id): object
    {
        $fila = DB::table('invoices')->where('id', $id)->first();

        if ($fila === null) {
            throw new RuntimeException('No existe esa factura.');
        }

        return $fila;
    }

    /** La sociedad que emite, con el ISO2 de su país. */
    private static function sociedad(int $id): object
    {
        $fila = DB::table('legal_entities as le')
            ->join('countries as c', 'c.id', '=', 'le.country_id')
            ->where('le.id', $id)
            ->first(['le.id', 'le.legal_name', 'le.tax_id_number', 'le.address_line1',
                'le.address_line2', 'le.city', 'le.region', 'le.country_id', 'le.timezone',
                // 9.9f: lo que hace falta para congelar la localidad al emitir.
                'le.tax_location_code', 'le.district', 'le.establishment_code',
                'c.iso2', 'c.name as pais']);

        if ($fila === null) {
            throw new RuntimeException('No existe esa sociedad.');
        }

        return $fila;
    }

    /** El perfil fiscal vigente del cliente, que es a quien se factura. */
    private static function receptor(int $clienteId): object
    {
        $pais = DB::table('client_organizations')->where('id', $clienteId)->value('country_id');

        if ($pais === null) {
            throw new RuntimeException('No existe ese cliente.');
        }

        $perfil = PerfilesFiscales::vigente($clienteId, (int) $pais);

        if ($perfil === null) {
            throw new RuntimeException(
                'Ese cliente no tiene perfil fiscal vigente: sin nombre legal ni identificador '
                .'no se puede emitir a su nombre.',
            );
        }

        $iso2 = DB::table('countries')->where('id', $perfil->country_id)->value('iso2');

        return (object) ((array) $perfil + ['iso2' => (string) $iso2]);
    }

    private static function domicilio(object $fila): string
    {
        $partes = array_filter([
            (string) ($fila->address_line1 ?? ''),
            (string) ($fila->address_line2 ?? ''),
            (string) ($fila->city ?? ''),
            (string) ($fila->region ?? ''),
        ], static fn (string $p): bool => trim($p) !== '');

        return mb_substr(implode(', ', $partes), 0, 300);
    }

    // ------------------------------------------------- aritmética exacta

    /**
     * El impuesto de un importe, redondeado a cuatro decimales.
     *
     * En SQL y no en PHP: **no hay `bcmath`** en el servidor del cliente, y un
     * `float` no es una moneda. `DECIMAL(28,12)` deja margen de sobra por debajo
     * del redondeo final, que es lo que evita que el céntimo salga por un lado
     * y no por el otro.
     */
    private static function impuestoDe(string $base, string $porcentaje): string
    {
        return self::decimal(
            'ROUND(CAST(? AS DECIMAL(28,12)) * CAST(? AS DECIMAL(28,12)) / 100, 4)',
            [$base, $porcentaje],
        );
    }

    private static function multiplicar(string $a, string $b): string
    {
        return self::decimal(
            'ROUND(CAST(? AS DECIMAL(28,12)) * CAST(? AS DECIMAL(28,12)), 4)', [$a, $b],
        );
    }

    private static function sumar(string $a, string $b): string
    {
        return self::decimal(
            'ROUND(CAST(? AS DECIMAL(28,12)) + CAST(? AS DECIMAL(28,12)), 4)', [$a, $b],
        );
    }

    /** @param list<string> $valores */
    private static function decimal(string $expresion, array $valores): string
    {
        /** @var object{v: string}|null $fila */
        $fila = DB::selectOne('SELECT CAST('.$expresion.' AS CHAR) AS v', $valores);

        return $fila === null ? '0' : (string) $fila->v;
    }
}
