<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finanzas: costos, lotes de pago, pagos, libro mayor, facturas y cobros.
 *
 * Es la iteración con más reglas que imponer del proyecto, y la que peor
 * envejece si se hace mal, porque el error no se ve: un saldo descuadrado no
 * lanza ninguna excepción, simplemente es incorrecto durante meses.
 *
 * Tres decisiones estructurales, todas discutidas en docs/fase-2/2.13:
 *
 *  1. **El saldo de un creador no es una columna** (BR-FIN-001). Es
 *     SUM(ledger_entries.amount). Una columna de saldo es un caché que se
 *     desincroniza, y cuando lo hace nadie sabe cuál de los dos números es el
 *     bueno. Aquí no hay dos números.
 *
 *  2. **El libro mayor es sólo-inserción** (BR-FIN-002). Una corrección es un
 *     asiento de reversión, jamás una edición. Impuesto por disparador, no por
 *     convención: quien tenga la contraseña de la base tampoco puede.
 *
 *  3. **La segregación de funciones vive en la base** (BR-FIN-005). Quien crea
 *     un lote de pago no puede aprobarlo. Una pantalla que valida eso se
 *     salta con una llamada directa; una restricción no.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Costos directos imputables a una campaña. Alimentan el margen
        // (BR-FIN-011), que es información interna: nunca se muestra al cliente
        // ni al creador.
        Schema::create('campaign_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id');
            $table->string('cost_type', 20);
            $table->string('description', 255);
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->date('incurred_on');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            // Un costo mal tecleado se anula, no se borra: si desapareciera, el
            // margen de ayer dejaría de poder reconstruirse.
            $table->dateTime('voided_at', 3)->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('voided_reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['campaign_id', 'cost_type'], 'ix_cco_campaign');
            $table->index('currency_code', 'ix_cco_currency');
            $table->index('file_id', 'ix_cco_file');
            $table->index('created_by_user_id', 'ix_cco_user');
            $table->index('voided_by_user_id', 'ix_cco_voider');

            $table->foreign('campaign_id', 'fk_cco_campaign')
                ->references('id')->on('campaigns')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_cco_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('file_id', 'fk_cco_file')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'fk_cco_user')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('voided_by_user_id', 'fk_cco_voider')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // El lote es la unidad de aprobación. Existe para que la doble firma
        // tenga dónde vivir.
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('code', 20);
            $table->foreignId('legal_entity_id');
            $table->char('currency_code', 3);
            $table->string('status', 15)->default('draft');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->dateTime('executed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_pb_uuid2');
            $table->unique('code', 'uq_pbatch_code');
            $table->index(['legal_entity_id', 'status'], 'ix_pbatch_entity');
            $table->index('created_by_user_id', 'ix_pbatch_creator');
            $table->index('approved_by_user_id', 'ix_pbatch_approver');
            $table->index('currency_code', 'ix_pbatch_currency');

            $table->foreign('legal_entity_id', 'fk_pbatch_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_pbatch_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'fk_pbatch_creator')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'fk_pbatch_approver')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // La ejecución bancaria.
        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            // NOT NULL a propósito: si un pago pudiera existir sin lote, la
            // doble aprobación se saltaría creando pagos sueltos. Un pago único
            // es un lote de uno. La segregación no admite puerta trasera.
            $table->foreignId('payout_batch_id');
            $table->foreignId('creator_id');
            $table->foreignId('payment_method_id');
            // Copia congelada: hay que poder reconstruir a dónde se envió el
            // dinero aunque el creador cambie de cuenta mañana.
            $table->string('beneficiary_name_snapshot', 160);
            $table->string('account_masked_snapshot', 30);
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->string('status', 15)->default('pending');
            $table->string('bank_reference', 80)->nullable();
            // DATE y no DATETIME: la fecha valor es un día, no un instante.
            $table->date('value_date')->nullable();
            $table->dateTime('sent_at', 3)->nullable();
            $table->dateTime('confirmed_at', 3)->nullable();
            $table->dateTime('returned_at', 3)->nullable();
            $table->string('return_reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_payout_uuid');
            $table->index(['payout_batch_id', 'status'], 'ix_payout_batch');
            $table->index(['creator_id', 'status'], 'ix_payout_creator');
            $table->index('payment_method_id', 'ix_payout_method');
            $table->index('currency_code', 'ix_payout_currency');
            $table->index('value_date', 'ix_payout_value_date');

            $table->foreign('payout_batch_id', 'fk_payout_batch')
                ->references('id')->on('payout_batches')->restrictOnDelete();
            $table->foreign('creator_id', 'fk_payout_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('payment_method_id', 'fk_payout_method')
                ->references('id')->on('creator_payment_methods')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_payout_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
        });

        // EL LIBRO MAYOR. Sólo inserción.
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('creator_id');
            $table->string('entry_type', 20);
            // Positivo suma al saldo del creador, negativo resta. Cero no es
            // un asiento.
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->string('status', 15)->default('accrued');
            $table->unsignedBigInteger('campaign_creator_id')->nullable();
            $table->unsignedBigInteger('payout_id')->nullable();
            // La tasa aplicada se congela con su fecha y su fuente (BR-FIN-009).
            // Los históricos no se recalculan nunca.
            $table->decimal('exchange_rate_snapshot', 18, 8)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 40)->nullable();
            $table->char('base_currency_code', 3)->nullable();
            $table->decimal('base_amount', 18, 4)->nullable();
            // Q-40: un asiento de retención congela la tasa aplicada y la norma
            // que la sustenta. Sin esto, cambiar la tasa mañana reescribiría la
            // explicación de las retenciones de ayer.
            $table->decimal('withholding_rate_snapshot', 7, 4)->nullable();
            $table->string('withholding_basis_snapshot', 160)->nullable();
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->string('description', 255);
            // occurred_at es tiempo de negocio (cuándo ocurrió el hecho
            // económico); created_at es tiempo de sistema (cuándo lo supimos).
            // Auditoría necesita los dos: un asiento con fecha de hace tres
            // meses insertado hoy es una señal, no un dato más.
            $table->dateTime('occurred_at', 3);
            $table->dateTime('created_at', 3);
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->unique('uuid', 'uq_ledger_uuid');
            // Un asiento de pago por payout, no dos (BR-FIN-013). NULL no
            // colisiona en un índice único, así que los demás asientos caben.
            $table->unique('payout_id', 'uq_ledger_payout');
            // Un asiento se revierte una sola vez.
            $table->unique('reverses_entry_id', 'uq_ledger_reverses');
            $table->index(['creator_id', 'status', 'occurred_at'], 'ix_ledger_creator');
            $table->index('campaign_creator_id', 'ix_ledger_participation');
            $table->index(['entry_type', 'occurred_at'], 'ix_ledger_type');
            $table->index('currency_code', 'ix_ledger_currency');
            $table->index('created_by_user_id', 'ix_ledger_user');

            $table->foreign('creator_id', 'fk_ledger_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('campaign_creator_id', 'fk_ledger_participation')
                ->references('id')->on('campaign_creators')->restrictOnDelete();
            $table->foreign('payout_id', 'fk_ledger_payout')
                ->references('id')->on('payouts')->restrictOnDelete();
            $table->foreign('reverses_entry_id', 'fk_ledger_reverses')
                ->references('id')->on('ledger_entries')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_ledger_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('base_currency_code', 'fk_ledger_base_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'fk_ledger_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('legal_entity_id');
            $table->foreignId('client_organization_id');
            $table->foreignId('client_tax_profile_id');
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('document_type', 20)->default('invoice');
            $table->string('series', 10);
            $table->unsignedBigInteger('number');
            $table->date('issue_date');
            $table->date('due_date');
            $table->char('currency_code', 3);
            // DEC-047: se factura todo desde Perú, así que el régimen NO es
            // constante. Al cliente peruano se le grava con IGV; al cliente del
            // exterior la operación califica como exportación de servicios y va
            // sin IGV. Guardar solo el importe del impuesto perdería el *por
            // qué* fue ese importe, que es justo lo que pregunta una
            // fiscalización tres años después.
            $table->string('tax_regime', 15)->default('gravado');
            $table->decimal('subtotal_amount', 18, 4);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4);
            $table->string('status', 15)->default('draft');
            // Copia congelada del emisor (BR-LE-005). La sociedad cambia de
            // domicilio; la factura de ayer no. Sin esto habría que reimprimir
            // el pasado cada vez que cambia un dato registral.
            $table->string('issuer_legal_name_snapshot', 200);
            $table->string('issuer_tax_id_snapshot', 40);
            $table->string('issuer_address_snapshot', 300);
            $table->string('receiver_legal_name_snapshot', 200);
            $table->string('receiver_tax_id_snapshot', 40);
            $table->string('receiver_address_snapshot', 300);
            // El país del receptor también se congela: determina si estaba
            // domiciliado, y sin él no se puede reconstruir por qué la factura
            // fue gravada o exportación.
            $table->char('receiver_country_snapshot', 2);
            $table->string('integration_connection_snapshot', 60)->nullable();
            $table->string('external_status', 30)->nullable();
            $table->unsignedBigInteger('file_id')->nullable();
            $table->dateTime('issued_at', 3)->nullable();
            $table->dateTime('voided_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_inv_uuid2');
            // Serie y correlativo únicos por sociedad: exigencia de SUNAT.
            $table->unique(['legal_entity_id', 'document_type', 'series', 'number'], 'uq_invoice_number');
            $table->index(['client_organization_id', 'status'], 'ix_invoice_client');
            $table->index('campaign_id', 'ix_invoice_campaign');
            $table->index(['issue_date', 'status'], 'ix_invoice_issue');
            $table->index('client_tax_profile_id', 'ix_invoice_profile');
            $table->index('currency_code', 'ix_invoice_currency');
            $table->index('file_id', 'ix_invoice_file');

            $table->foreign('legal_entity_id', 'fk_invoice_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
            $table->foreign('client_organization_id', 'fk_invoice_client')
                ->references('id')->on('client_organizations')->restrictOnDelete();
            $table->foreign('client_tax_profile_id', 'fk_invoice_profile')
                ->references('id')->on('client_tax_profiles')->restrictOnDelete();
            $table->foreign('campaign_id', 'fk_invoice_campaign')
                ->references('id')->on('campaigns')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_invoice_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('file_id', 'fk_invoice_file')
                ->references('id')->on('files')->restrictOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 300);
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_subtotal', 18, 4);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('line_tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);

            $table->unique(['invoice_id', 'line_number'], 'uq_iline_number');
            $table->foreign('invoice_id', 'fk_iline_invoice')
                ->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('invoice_id');
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->string('method', 20)->default('transfer');
            $table->string('reference', 80)->nullable();
            $table->date('received_on');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('registered_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();

            $table->unique('uuid', 'uq_payment_uuid');
            $table->index(['invoice_id', 'received_on'], 'ix_payment_invoice');
            $table->index('currency_code', 'ix_payment_currency');
            $table->index('file_id', 'ix_payment_file');
            $table->index('registered_by_user_id', 'ix_payment_user');

            $table->foreign('invoice_id', 'fk_payment_invoice')
                ->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_payment_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('file_id', 'fk_payment_file')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('registered_by_user_id', 'fk_payment_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        foreach (self::inmutabilidad() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::disparadoresInmutabilidad()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_batches');
        Schema::dropIfExists('campaign_costs');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['campaign_costs', 'ck_cco_type', "cost_type IN ('product','shipping','production','media','tool','other')", ['cost_type'], 'Tipo de costo no valido.'],
            ['campaign_costs', 'ck_cco_amount', 'amount >= 0', ['amount'], 'Un costo no puede ser negativo.'],
            // Una anulación a medias no es una anulación: sin motivo y sin
            // responsable no se puede auditar.
            ['campaign_costs', 'ck_cco_voided', '(voided_at IS NULL AND voided_by_user_id IS NULL AND voided_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND voided_reason IS NOT NULL)', ['voided_at', 'voided_by_user_id', 'voided_reason'], 'Una anulacion exige fecha, responsable y motivo.'],

            ['payout_batches', 'ck_pbatch_status', "status IN ('draft','pending_approval','approved','executing','executed','cancelled')", ['status'], 'Estado de lote no valido.'],
            // BR-FIN-005. Quien crea el lote no puede aprobarlo.
            ['payout_batches', 'ck_pbatch_segregation', 'approved_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id', ['approved_by_user_id', 'created_by_user_id'], 'Quien crea un lote de pago no puede aprobarlo (BR-FIN-005).'],
            ['payout_batches', 'ck_pbatch_approved', "status NOT IN ('approved','executing','executed') OR (approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL)", ['status', 'approved_by_user_id', 'approved_at'], 'Un lote aprobado exige aprobador y fecha.'],
            ['payout_batches', 'ck_pbatch_executed', "status <> 'executed' OR executed_at IS NOT NULL", ['status', 'executed_at'], 'Un lote ejecutado exige su fecha de ejecucion.'],
            ['payout_batches', 'ck_pbatch_approval_order', 'executed_at IS NULL OR approved_at IS NULL OR executed_at >= approved_at', ['executed_at', 'approved_at'], 'Un lote no puede ejecutarse antes de aprobarse.'],

            ['payouts', 'ck_payout_status', "status IN ('pending','sent','confirmed','returned','cancelled')", ['status'], 'Estado de pago no valido.'],
            ['payouts', 'ck_payout_amount', 'amount > 0', ['amount'], 'Un pago debe ser mayor que cero.'],
            ['payouts', 'ck_payout_sent', "status NOT IN ('sent','confirmed') OR sent_at IS NOT NULL", ['status', 'sent_at'], 'Un pago enviado exige su fecha de envio.'],
            ['payouts', 'ck_payout_returned', "status <> 'returned' OR returned_at IS NOT NULL", ['status', 'returned_at'], 'Un pago devuelto exige su fecha de devolucion.'],

            ['ledger_entries', 'ck_ledger_type', "entry_type IN ('earning','payment','payment_reversal','adjustment','bonus','penalty','withholding')", ['entry_type'], 'Tipo de asiento no valido.'],
            ['ledger_entries', 'ck_ledger_status', "status IN ('accrued','payable','paid','on_hold','void')", ['status'], 'Estado de asiento no valido.'],
            // Un asiento de importe cero no dice nada y ensucia el saldo.
            ['ledger_entries', 'ck_ledger_amount', 'amount <> 0', ['amount'], 'Un asiento de importe cero no es un asiento.'],
            // El signo de cada tipo está determinado salvo en 'adjustment', que
            // existe justamente para poder ir en cualquier dirección.
            ['ledger_entries', 'ck_ledger_sign', "(entry_type IN ('earning','bonus','payment_reversal') AND amount > 0) OR (entry_type IN ('payment','penalty','withholding') AND amount < 0) OR (entry_type = 'adjustment')", ['entry_type', 'amount'], 'El signo del importe no corresponde al tipo de asiento.'],
            // Si hay conversión, tiene que estar completa.
            ['ledger_entries', 'ck_ledger_fx', 'exchange_rate_snapshot IS NULL OR (exchange_rate_date IS NOT NULL AND exchange_rate_source IS NOT NULL AND base_currency_code IS NOT NULL AND base_amount IS NOT NULL)', ['exchange_rate_snapshot', 'exchange_rate_date', 'exchange_rate_source', 'base_currency_code', 'base_amount'], 'Una conversion exige tasa, fecha, fuente, moneda base e importe base.'],
            ['ledger_entries', 'ck_ledger_payout_link', "(entry_type = 'payment') = (payout_id IS NOT NULL)", ['entry_type', 'payout_id'], 'Solo un asiento de pago lleva payout, y siempre lo lleva.'],
            ['ledger_entries', 'ck_ledger_reversal', "entry_type <> 'payment_reversal' OR reverses_entry_id IS NOT NULL", ['entry_type', 'reverses_entry_id'], 'Una reversion debe decir que asiento corrige.'],
            // Un devengo sin participación es dinero sin origen trazable.
            ['ledger_entries', 'ck_ledger_earning_link', "entry_type <> 'earning' OR campaign_creator_id IS NOT NULL", ['entry_type', 'campaign_creator_id'], 'Un devengo exige la participacion en campana que lo origina.'],
            // Una retención sin tasa ni norma no se puede explicar.
            ['ledger_entries', 'ck_ledger_withholding', "(entry_type = 'withholding') = (withholding_rate_snapshot IS NOT NULL AND withholding_basis_snapshot IS NOT NULL)", ['entry_type', 'withholding_rate_snapshot', 'withholding_basis_snapshot'], 'Una retencion exige tasa y norma; y solo una retencion las lleva.'],
            ['ledger_entries', 'ck_ledger_withholding_rate', 'withholding_rate_snapshot IS NULL OR (withholding_rate_snapshot > 0 AND withholding_rate_snapshot <= 100)', ['withholding_rate_snapshot'], 'La tasa de retencion debe estar entre 0 y 100.'],
            ['ledger_entries', 'ck_ledger_reverses_type', "reverses_entry_id IS NULL OR entry_type IN ('payment_reversal','adjustment')", ['reverses_entry_id', 'entry_type'], 'Solo una reversion o un ajuste pueden corregir otro asiento.'],

            ['invoices', 'ck_invoice_status', "status IN ('draft','issued','sent','paid','partially_paid','voided','rejected')", ['status'], 'Estado de factura no valido.'],
            ['invoices', 'ck_invoice_type', "document_type IN ('invoice','boleta','credit_note','debit_note')", ['document_type'], 'Tipo de documento no valido.'],
            ['invoices', 'ck_invoice_amounts', 'subtotal_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0', ['subtotal_amount', 'tax_amount', 'total_amount'], 'Los importes de una factura no pueden ser negativos.'],
            // La aritmética la comprueba la base, no quien teclea.
            ['invoices', 'ck_invoice_math', 'total_amount = subtotal_amount + tax_amount', ['total_amount', 'subtotal_amount', 'tax_amount'], 'El total debe ser subtotal mas impuesto.'],
            ['invoices', 'ck_invoice_dates', 'due_date >= issue_date', ['due_date', 'issue_date'], 'El vencimiento no puede ser anterior a la emision.'],
            ['invoices', 'ck_invoice_number', 'number >= 1', ['number'], 'El correlativo empieza en 1.'],
            ['invoices', 'ck_invoice_regime', "tax_regime IN ('gravado','exportacion','exonerado','inafecto')", ['tax_regime'], 'Regimen tributario no valido.'],
            // Una exportación de servicios no lleva IGV.
            ['invoices', 'ck_invoice_regime_tax', "tax_regime = 'gravado' OR tax_amount = 0", ['tax_regime', 'tax_amount'], 'Solo una operacion gravada lleva impuesto.'],
            ['invoices', 'ck_invoice_regime_country', "tax_regime <> 'exportacion' OR receiver_country_snapshot <> 'PE'", ['tax_regime', 'receiver_country_snapshot'], 'No se exporta un servicio a un cliente domiciliado en Peru.'],
            ['invoices', 'ck_invoice_issued', "status = 'draft' OR issued_at IS NOT NULL", ['status', 'issued_at'], 'Una factura emitida exige su sello de emision.'],
            ['invoices', 'ck_invoice_voided', "status <> 'voided' OR voided_at IS NOT NULL", ['status', 'voided_at'], 'Una factura anulada exige su fecha de anulacion.'],

            ['invoice_lines', 'ck_iline_quantity', 'quantity > 0', ['quantity'], 'La cantidad debe ser mayor que cero.'],
            ['invoice_lines', 'ck_iline_amounts', 'unit_price >= 0 AND line_subtotal >= 0 AND line_tax >= 0 AND line_total >= 0', ['unit_price', 'line_subtotal', 'line_tax', 'line_total'], 'Los importes de una linea no pueden ser negativos.'],
            ['invoice_lines', 'ck_iline_math', 'line_total = line_subtotal + line_tax', ['line_total', 'line_subtotal', 'line_tax'], 'El total de linea debe ser subtotal mas impuesto.'],

            ['payments', 'ck_payment_amount', 'amount > 0', ['amount'], 'Un cobro debe ser mayor que cero.'],
            ['payments', 'ck_payment_method', "method IN ('transfer','deposit','check','card','other')", ['method'], 'Medio de cobro no valido.'],
        ];
    }

    /**
     * Disparadores de inmutabilidad.
     *
     * No son restricciones del compilador: van igual en los dos motores, porque
     * expresan algo que ningún CHECK puede expresar — prohibir un *verbo*, no
     * un valor. Implementan la regla del cliente "la información financiera
     * nunca se elimina físicamente", que aquí deja de ser una promesa de la
     * capa de aplicación y pasa a ser física.
     *
     * @return array<string, string> nombre => cuerpo
     */
    private static function disparadoresInmutabilidad(): array
    {
        return [
            'tg_ledger_no_update' => <<<'SQL'
                BEFORE UPDATE ON `ledger_entries`
                FOR EACH ROW
                BEGIN
                  IF NOT (NEW.uuid <=> OLD.uuid)
                     OR NOT (NEW.creator_id <=> OLD.creator_id)
                     OR NOT (NEW.entry_type <=> OLD.entry_type)
                     OR NOT (NEW.amount <=> OLD.amount)
                     OR NOT (NEW.currency_code <=> OLD.currency_code)
                     OR NOT (NEW.campaign_creator_id <=> OLD.campaign_creator_id)
                     OR NOT (NEW.payout_id <=> OLD.payout_id)
                     OR NOT (NEW.exchange_rate_snapshot <=> OLD.exchange_rate_snapshot)
                     OR NOT (NEW.exchange_rate_date <=> OLD.exchange_rate_date)
                     OR NOT (NEW.exchange_rate_source <=> OLD.exchange_rate_source)
                     OR NOT (NEW.base_currency_code <=> OLD.base_currency_code)
                     OR NOT (NEW.base_amount <=> OLD.base_amount)
                     OR NOT (NEW.withholding_rate_snapshot <=> OLD.withholding_rate_snapshot)
                     OR NOT (NEW.withholding_basis_snapshot <=> OLD.withholding_basis_snapshot)
                     OR NOT (NEW.reverses_entry_id <=> OLD.reverses_entry_id)
                     OR NOT (NEW.description <=> OLD.description)
                     OR NOT (NEW.occurred_at <=> OLD.occurred_at)
                     OR NOT (NEW.created_at <=> OLD.created_at)
                  THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'ledger_entries es solo-insercion: corrija con un asiento de reversion (BR-FIN-002).';
                  END IF;
                END
                SQL,
            'tg_ledger_no_delete' => <<<'SQL'
                BEFORE DELETE ON `ledger_entries`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ledger_entries no admite borrado fisico (BR-FIN-001).';
                END
                SQL,
            'tg_invoice_no_delete' => <<<'SQL'
                BEFORE DELETE ON `invoices`
                FOR EACH ROW
                BEGIN
                  IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Una factura emitida no se borra: se anula (status=voided).';
                  END IF;
                END
                SQL,
            'tg_iline_no_delete' => <<<'SQL'
                BEFORE DELETE ON `invoice_lines`
                FOR EACH ROW
                BEGIN
                  IF (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'No se alteran las lineas de una factura ya emitida.';
                  END IF;
                END
                SQL,
            'tg_payout_no_delete' => <<<'SQL'
                BEFORE DELETE ON `payouts`
                FOR EACH ROW
                BEGIN
                  IF OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un pago ya enviado al banco no se borra: se marca devuelto o cancelado.';
                  END IF;
                END
                SQL,
            'tg_payment_no_delete' => <<<'SQL'
                BEFORE DELETE ON `payments`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Un cobro registrado no se borra: registre el extorno correspondiente.';
                END
                SQL,
            'tg_cco_no_delete' => <<<'SQL'
                BEFORE DELETE ON `campaign_costs`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Un costo de campana no se borra: se anula (voided_at), para poder reconstruir el margen historico.';
                END
                SQL,
        ];
    }

    /** @return list<string> */
    private static function inmutabilidad(): array
    {
        $sql = [];
        foreach (self::disparadoresInmutabilidad() as $nombre => $cuerpo) {
            $sql[] = "CREATE TRIGGER `{$nombre}` {$cuerpo}";
        }

        return $sql;
    }
};
