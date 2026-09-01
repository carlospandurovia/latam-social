<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La factura deja de ser una tabla con la mesa puesta (9.9b).
 *
 * `invoices` existe desde la Fase 2 y **nadie escribe en ella**. Antes de que
 * alguien lo haga hay que arreglar cinco cosas que sólo se ven cuando de verdad
 * se intenta emitir. Ninguna es un detalle: las cinco son la diferencia entre un
 * comprobante defendible y uno que no se puede explicar.
 *
 * ### 1. Un borrador no tiene número (y no debe tenerlo)
 *
 * `series` y `number` eran obligatorias. Con eso, empezar a redactar una factura
 * obligaba a **quemar un correlativo**, y borrar el borrador dejaba un hueco en
 * la numeración ante SUNAT. El número es el recurso escaso: se pide en el
 * instante de emitir, no en el de empezar a escribir. Ahora las dos admiten
 * `NULL` mientras el documento es borrador, y `ck_invoice_numerada` exige las
 * dos —y el número reservado de `9.12`— en cuanto deja de serlo.
 *
 * ### 2. `ck_invoice_regime_country` tenía «PE» escrito dentro
 *
 * La regla que se quería expresar es *«no se exporta un servicio a un cliente
 * domiciliado en el país que emite»*, y estaba escrita como *«…domiciliado en
 * Perú»*. Es `DEC-190` roto en el esquema. Para poder decirlo bien faltaba un
 * dato que la factura no congelaba: **el país del emisor**. Se congelaba el del
 * receptor y no el propio, así que la comparación no se podía hacer sin ir a
 * `legal_entities` —que para entonces ya puede haber cambiado—.
 *
 * Con `issuer_country_snapshot`, la regla se escribe sin nombrar ningún país.
 *
 * ### 3. Una factura emitida se podía reescribir en silencio
 *
 * `ledger_entries` tenía `tg_ledger_no_update` desde la Fase 2. `invoices`, que
 * es el documento que ve la administración tributaria, **no tenía ninguno**:
 * un `UPDATE` podía cambiarle el importe a una factura ya emitida y no quedaba
 * rastro. `tg_invoice_emision` congela el núcleo fiscal en cuanto el documento
 * deja de ser borrador; lo único que sigue moviéndose es su estado, el sello de
 * la administración y el archivo.
 *
 * ### 4. Las líneas no tenían que sumar el total
 *
 * `ck_invoice_math` comprobaba `total = subtotal + impuesto`, que es la suma
 * fácil. La que se descuadra en la vida real es la otra: **las líneas contra la
 * cabecera**. Un comprobante cuyas líneas impresas no suman su propio total lo
 * rechaza SUNAT y no se puede defender. Es cruzada, así que va en el mismo
 * disparador, en el instante de emitir.
 *
 * ### 5. Anular no exigía decir por qué
 *
 * `voided_at` sin `void_reason`. La misma lección que `document_numbers` en
 * `9.12` y `client_leads` en `9.21c`: un hueco explicado es defendible; uno
 * mudo, no.
 *
 * ### Lo que se congela, y por qué tanto
 *
 * `tax_rate_id` y `tax_rate_snapshot` dicen **con qué tasa se calculó**. Con
 * `9.9a` la tasa ya se puede preguntar por fecha, pero preguntarla otra vez tres
 * años después obliga a confiar en que nadie tocó la vigencia. La copia no
 * confía en nadie: la factura explica su propio importe sin salir de su fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La 'PE' escrita dentro. Se va antes de anadir la columna que permite
        // escribirla bien, porque la nueva la sustituye.
        Restriccion::quitar('invoices', 'ck_invoice_regime_country');
        Restriccion::quitar('invoices', 'ck_invoice_number');

        Schema::table('invoices', function (Blueprint $table): void {
            // El numero se pide al emitir, no al empezar a escribir.
            $table->string('series', 10)->nullable()->change();
            $table->unsignedBigInteger('number')->nullable()->change();

            // El que faltaba para poder comparar sin nombrar ningun pais.
            $table->char('issuer_country_snapshot', 2)->nullable()->after('issuer_address_snapshot');

            // De donde salio el numero (`9.12`). Sin foranea no habria forma de
            // saber que numero gasto esta factura mas que buscando por texto.
            $table->unsignedBigInteger('document_number_id')->nullable()->after('number');

            // Con que tasa se calculo (`9.9a`). La fila Y el porcentaje: la fila
            // para llegar al codigo de catalogo que viaja en el XML de `9.9c`, y
            // el porcentaje para que la factura se explique sin salir de si.
            $table->unsignedBigInteger('tax_rate_id')->nullable()->after('tax_regime');
            $table->decimal('tax_rate_snapshot', 7, 4)->nullable()->after('tax_rate_id');

            $table->unsignedBigInteger('issued_by_user_id')->nullable()->after('issued_at');
            $table->string('void_reason', 255)->nullable()->after('voided_at');

            // Un numero reservado se gasta UNA vez. Es la misma unica que
            // `uq_dn_number` por el otro lado: dos facturas apuntando al mismo
            // numero serian dos comprobantes con el mismo correlativo.
            $table->unique('document_number_id', 'uq_invoice_dnumber');
            $table->index('tax_rate_id', 'ix_invoice_tax_rate');
            $table->index('issued_by_user_id', 'ix_invoice_issuer_user');

            $table->foreign('document_number_id', 'fk_invoice_dnumber')
                ->references('id')->on('document_numbers')->restrictOnDelete();
            $table->foreign('tax_rate_id', 'fk_invoice_tax_rate')
                ->references('id')->on('tax_rates')->restrictOnDelete();
            $table->foreign('issued_by_user_id', 'fk_invoice_issuer_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::disparadores()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign('fk_invoice_dnumber');
            $table->dropForeign('fk_invoice_tax_rate');
            $table->dropForeign('fk_invoice_issuer_user');
            $table->dropUnique('uq_invoice_dnumber');
            $table->dropIndex('ix_invoice_tax_rate');
            $table->dropIndex('ix_invoice_issuer_user');
            $table->dropColumn(['issuer_country_snapshot', 'document_number_id',
                'tax_rate_id', 'tax_rate_snapshot', 'issued_by_user_id', 'void_reason']);
            $table->string('series', 10)->nullable(false)->change();
            $table->unsignedBigInteger('number')->nullable(false)->change();
        });

        Restriccion::comprobacion(
            tabla: 'invoices', nombre: 'ck_invoice_number', expresion: 'number >= 1',
            columnas: ['number'], mensaje: 'El correlativo empieza en 1.',
        );
        Restriccion::comprobacion(
            tabla: 'invoices', nombre: 'ck_invoice_regime_country',
            expresion: "tax_regime <> 'exportacion' OR receiver_country_snapshot <> 'PE'",
            columnas: ['tax_regime', 'receiver_country_snapshot'],
            mensaje: 'No se exporta un servicio a un cliente domiciliado en Peru.',
        );
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Sigue empezando en 1; lo que ahora se admite es que todavia no lo
            // haya. `NULL OR ...` y no al reves: la mitad de este proyecto ha
            // descubierto a golpes que un CHECK solo rechaza cuando es FALSO.
            ['invoices', 'ck_invoice_number', 'number IS NULL OR number >= 1',
                ['number'], 'El correlativo empieza en 1.'],

            // La regla de `DEC-047`, ahora sin ningun pais escrito dentro: no se
            // exporta un servicio a alguien domiciliado donde se emite.
            ['invoices', 'ck_invoice_regime_country',
                "tax_regime <> 'exportacion' OR issuer_country_snapshot IS NULL"
                .' OR receiver_country_snapshot <> issuer_country_snapshot',
                ['tax_regime', 'issuer_country_snapshot', 'receiver_country_snapshot'],
                'No se exporta un servicio a un cliente domiciliado donde se emite.'],

            // Un documento que salio a la calle dice de que sociedad salio y de
            // que pais. Sin el pais no se puede rehacer por que fue gravado.
            ['invoices', 'ck_invoice_emisor_pais',
                "status = 'draft' OR issuer_country_snapshot IS NOT NULL",
                ['status', 'issuer_country_snapshot'],
                'Una factura emitida dice desde que pais se emitio.'],

            // Emitida es lo mismo que numerada, y con el numero de `9.12`
            // gastado: un comprobante cuyo correlativo no sale del libro no se
            // puede cruzar con el libro.
            ['invoices', 'ck_invoice_numerada',
                "status = 'draft' OR (series IS NOT NULL AND number IS NOT NULL"
                .' AND document_number_id IS NOT NULL)',
                ['status', 'series', 'number', 'document_number_id'],
                'Una factura emitida lleva serie y correlativo del libro.'],

            // Y un borrador NO lo lleva. Es la otra mitad, y sin ella el numero
            // se podria seguir reservando al empezar a escribir --que es
            // exactamente el defecto que esta iteracion vino a quitar--.
            ['invoices', 'ck_invoice_borrador_sin_numero',
                "status <> 'draft' OR (series IS NULL AND number IS NULL"
                .' AND document_number_id IS NULL)',
                ['status', 'series', 'number', 'document_number_id'],
                'Un borrador todavia no gasta numero: se pide al emitir.'],

            // Gravado con impuesto cero es la factura que `9.9a` existe para
            // impedir: el IGV saldria en cero sin que nadie lo decidiera. Se
            // exceptua el subtotal cero, que es un canje y no lleva nada.
            ['invoices', 'ck_invoice_gravado_con_impuesto',
                "tax_regime <> 'gravado' OR subtotal_amount = 0 OR tax_amount > 0",
                ['tax_regime', 'subtotal_amount', 'tax_amount'],
                'Una operacion gravada con impuesto cero: falta la tasa del pais.'],

            // Y dice CON QUE tasa se calculo, no solo cuanto salio.
            ['invoices', 'ck_invoice_gravado_con_tasa',
                "tax_regime <> 'gravado' OR status = 'draft' OR tax_rate_snapshot IS NOT NULL",
                ['tax_regime', 'status', 'tax_rate_snapshot'],
                'Una factura gravada dice con que tasa se calculo.'],

            // La misma leccion que `9.12` y `9.21c`: un hueco explicado es
            // defendible; uno mudo, no.
            ['invoices', 'ck_invoice_void_reason',
                "status <> 'voided' OR (void_reason IS NOT NULL"
                .' AND CHAR_LENGTH(TRIM(void_reason)) >= 10)',
                ['status', 'void_reason'],
                'Anular un comprobante exige decir por que.'],
        ];
    }

    /**
     * Lo que ningun CHECK puede decir.
     *
     * Dos cosas cruzadas y un verbo prohibido, y las tres en el mismo sitio
     * porque las tres hablan del mismo instante: **el de emitir**.
     *
     * @return array<string, string> nombre => cuerpo
     */
    private static function disparadores(): array
    {
        return [
            'tg_invoice_emision' => <<<'SQL'
                BEFORE UPDATE ON `invoices`
                FOR EACH ROW
                BEGIN
                  DECLARE v_lineas INT DEFAULT 0;
                  DECLARE v_sub DECIMAL(18,4) DEFAULT 0;
                  DECLARE v_imp DECIMAL(18,4) DEFAULT 0;

                  IF OLD.status = 'draft' AND NEW.status <> 'draft' THEN
                    SELECT COUNT(*), COALESCE(SUM(line_subtotal),0), COALESCE(SUM(line_tax),0)
                      INTO v_lineas, v_sub, v_imp
                      FROM invoice_lines WHERE invoice_id = OLD.id;

                    IF v_lineas = 0 THEN
                      SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Una factura sin lineas no dice que se cobra.';
                    END IF;

                    IF v_sub <> NEW.subtotal_amount OR v_imp <> NEW.tax_amount THEN
                      SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Las lineas no suman el total: la factura no cuadra consigo misma.';
                    END IF;
                  END IF;

                  IF OLD.status <> 'draft' THEN
                    IF NOT (NEW.uuid <=> OLD.uuid)
                       OR NOT (NEW.legal_entity_id <=> OLD.legal_entity_id)
                       OR NOT (NEW.client_organization_id <=> OLD.client_organization_id)
                       OR NOT (NEW.client_tax_profile_id <=> OLD.client_tax_profile_id)
                       OR NOT (NEW.campaign_id <=> OLD.campaign_id)
                       OR NOT (NEW.document_type <=> OLD.document_type)
                       OR NOT (NEW.series <=> OLD.series)
                       OR NOT (NEW.number <=> OLD.number)
                       OR NOT (NEW.document_number_id <=> OLD.document_number_id)
                       OR NOT (NEW.issue_date <=> OLD.issue_date)
                       OR NOT (NEW.due_date <=> OLD.due_date)
                       OR NOT (NEW.currency_code <=> OLD.currency_code)
                       OR NOT (NEW.tax_regime <=> OLD.tax_regime)
                       OR NOT (NEW.tax_rate_id <=> OLD.tax_rate_id)
                       OR NOT (NEW.tax_rate_snapshot <=> OLD.tax_rate_snapshot)
                       OR NOT (NEW.subtotal_amount <=> OLD.subtotal_amount)
                       OR NOT (NEW.tax_amount <=> OLD.tax_amount)
                       OR NOT (NEW.total_amount <=> OLD.total_amount)
                       OR NOT (NEW.issuer_legal_name_snapshot <=> OLD.issuer_legal_name_snapshot)
                       OR NOT (NEW.issuer_tax_id_snapshot <=> OLD.issuer_tax_id_snapshot)
                       OR NOT (NEW.issuer_address_snapshot <=> OLD.issuer_address_snapshot)
                       OR NOT (NEW.issuer_country_snapshot <=> OLD.issuer_country_snapshot)
                       OR NOT (NEW.receiver_legal_name_snapshot <=> OLD.receiver_legal_name_snapshot)
                       OR NOT (NEW.receiver_tax_id_snapshot <=> OLD.receiver_tax_id_snapshot)
                       OR NOT (NEW.receiver_address_snapshot <=> OLD.receiver_address_snapshot)
                       OR NOT (NEW.receiver_country_snapshot <=> OLD.receiver_country_snapshot)
                       OR NOT (NEW.issued_at <=> OLD.issued_at)
                       OR NOT (NEW.issued_by_user_id <=> OLD.issued_by_user_id)
                       OR NOT (NEW.created_at <=> OLD.created_at)
                    THEN
                      SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Una factura emitida no se corrige: se anula y se emite otra.';
                    END IF;
                  END IF;
                END
                SQL,

            // Anadir una linea a una factura ya emitida cambia lo que dice el
            // documento sin tocar el documento. `tg_iline_no_delete` cubria el
            // borrado desde la Fase 2 y dejaba abiertas las otras dos puertas.
            'tg_iline_solo_borrador' => <<<'SQL'
                BEFORE INSERT ON `invoice_lines`
                FOR EACH ROW
                BEGIN
                  IF (SELECT status FROM invoices WHERE id = NEW.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'No se anaden lineas a una factura ya emitida.';
                  END IF;
                END
                SQL,

            'tg_iline_no_update' => <<<'SQL'
                BEFORE UPDATE ON `invoice_lines`
                FOR EACH ROW
                BEGIN
                  IF (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'No se alteran las lineas de una factura ya emitida.';
                  END IF;
                END
                SQL,
        ];
    }
};
