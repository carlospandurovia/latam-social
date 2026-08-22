<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo fiscal y lo bancario del creador. La iteración más sensible del proyecto.
 *
 * Tres reglas que gobiernan estas tablas:
 *  - El número de cuenta NUNCA se guarda en claro (§57).
 *  - Cambiar datos fiscales o medios de pago exige aprobación (BR-CREATOR-007).
 *  - Un medio nuevo o modificado no es elegible hasta pasar el enfriamiento
 *    y ser reverificado (BR-FIN-006).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Un creador puede tener régimen en más de un país: el peruano con RUC
        // que además factura desde España. Por eso cuelga del país.
        Schema::create('creator_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            $table->foreignId('country_id');
            // Cada país llama distinto a sus regímenes (RUS, RER, GENERAL,
            // AUTONOMO...). Texto controlado y no catálogo: añadir uno ocurre al
            // abrir mercado, que ya es un despliegue (docs 2.3 §7).
            $table->string('tax_regime_code', 30);
            $table->string('tax_id_type', 20);
            $table->string('tax_id_number', 40)->nullable();
            $table->string('issued_document_type', 30);
            // La retención se pacta con el régimen, no se inventa al pagar.
            // Q-40 / DEC-048. Antes era `boolean withholding_applies default false`,
            // y ahí estaba el fallo: «no se retiene» y «nadie lo ha mirado
            // todavía» eran el mismo valor. Un perfil se aprobaba con el defecto
            // puesto, el pago salía sin retención, y no había forma de
            // distinguir la decisión del olvido.
            $table->string('withholding_status', 20)->default('pending_review');
            $table->decimal('withholding_rate', 7, 4)->default(0);
            // La norma que sustenta la tasa. Sin esto la tasa es un número sin
            // padre, y dentro de tres años nadie sabrá si salió de la ley o de
            // una suposición de alguien que ya no trabaja aquí.
            $table->string('withholding_basis', 160)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('status', 15)->default('pending');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->string('rejection_note', 255)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['creator_id', 'status'], 'ix_ctp_creator');
            $table->index('country_id', 'ix_ctp_country');
            $table->index('approved_by_user_id', 'ix_ctp_approver');
            $table->index('created_by_user_id', 'ix_ctp_creator_user');
            $table->index('withholding_status', 'ix_ctp_withholding');
            $table->index(['tax_id_type', 'tax_id_number'], 'ix_ctp_taxid');

            $table->foreign('creator_id', 'fk_ctp_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('country_id', 'fk_ctp_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'fk_ctp_approver')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'fk_ctp_creator_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Un solo perfil vigente Y aprobado por creador y país. Los pendientes
        // conviven: alguien puede estar tramitando el cambio de régimen.
        DB::statement(
            'ALTER TABLE creator_tax_profiles ADD COLUMN current_gate TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL AND status = 'approved' THEN 1 ELSE NULL END) STORED",
        );
        DB::statement('ALTER TABLE creator_tax_profiles ADD UNIQUE KEY uq_ctp_current (current_gate, creator_id, country_id)');

        Schema::create('creator_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('creator_id');
            // docs 2.3 §10: si el beneficiario es el tutor, la cuenta es suya.
            // Sin esto, BR-FIN-003 validaba el medio de pago de otra persona.
            $table->string('owner_type', 10)->default('creator');
            $table->unsignedBigInteger('owner_guardian_id')->nullable();
            $table->string('method_type', 20);
            $table->foreignId('country_id');
            $table->char('currency_code', 3);
            $table->string('bank_name', 80)->nullable();
            $table->string('account_type', 15)->nullable();
            // Tres columnas y ninguna legible:
            //   _encrypted   el valor cifrado por la aplicación; la clave maestra
            //                vive fuera de la base (INTEGRATIONS_MASTER_KEY).
            //   _masked      lo único que se pinta en pantalla ("****4321").
            //   _fingerprint HMAC-SHA256, para detectar que dos creadores comparten
            //                cuenta SIN descifrar nada. Es señal de fraude, no
            //                error: por eso índice normal y no único.
            $table->text('account_number_encrypted');
            $table->string('account_number_masked', 30);
            $table->char('account_number_fingerprint', 64);
            $table->string('holder_name', 160);
            $table->string('holder_document_type', 20);
            $table->string('holder_document_number', 40);
            $table->string('status', 15)->default('pending');
            $table->dateTime('verified_at', 3)->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            // BR-FIN-006: un medio nuevo o modificado no es elegible hasta esta
            // fecha, aunque ya esté verificado. Es lo que frena el fraude de
            // "cambio la cuenta y cobro antes de que nadie mire".
            $table->dateTime('eligible_from', 3)->nullable();
            $table->boolean('is_default')->default(false);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_cpm_uuid');
            $table->index(['creator_id', 'status'], 'ix_cpm_creator');
            $table->index('account_number_fingerprint', 'ix_cpm_fingerprint');
            $table->index('owner_guardian_id', 'ix_cpm_guardian');
            $table->index('country_id', 'ix_cpm_country');
            $table->index('currency_code', 'ix_cpm_currency');
            $table->index('verified_by_user_id', 'ix_cpm_verifier');

            $table->foreign('creator_id', 'fk_cpm_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('owner_guardian_id', 'fk_cpm_guardian')
                ->references('id')->on('creator_guardians')->restrictOnDelete();
            $table->foreign('country_id', 'fk_cpm_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_cpm_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('verified_by_user_id', 'fk_cpm_verifier')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE creator_payment_methods ADD COLUMN default_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED',
        );
        DB::statement('ALTER TABLE creator_payment_methods ADD UNIQUE KEY uq_cpm_default (default_gate, creator_id)');

        Schema::create('creator_tax_documents', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('creator_id');
            // BR-CREATOR-010: si el creador es menor, lo emite el tutor.
            $table->unsignedBigInteger('issued_by_guardian_id')->nullable();
            $table->string('document_type', 30);
            $table->string('series', 10);
            $table->string('number', 20);
            // DATE y no DATETIME: la fecha de emisión es un DÍA en el país del
            // emisor (docs 2.3 §8). En UTC, un comprobante de fin de mes cae en
            // el periodo tributario equivocado.
            $table->date('issue_date');
            $table->char('currency_code', 3);
            $table->decimal('gross_amount', 18, 4);
            $table->decimal('withholding_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4);
            $table->unsignedBigInteger('file_id')->nullable();
            $table->string('status', 15)->default('received');
            $table->unsignedBigInteger('validated_by_user_id')->nullable();
            $table->dateTime('validated_at', 3)->nullable();
            $table->string('rejection_note', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_ctd_uuid');
            // El mismo emisor no puede entregar dos veces la misma serie y número.
            $table->unique(['creator_id', 'document_type', 'series', 'number'], 'uq_ctd_number');
            $table->index(['creator_id', 'status'], 'ix_ctd_creator');
            $table->index('issue_date', 'ix_ctd_issue_date');
            $table->index('issued_by_guardian_id', 'ix_ctd_guardian');
            $table->index('file_id', 'ix_ctd_file');
            $table->index('currency_code', 'ix_ctd_currency');
            $table->index('validated_by_user_id', 'ix_ctd_validator');

            $table->foreign('creator_id', 'fk_ctd_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('issued_by_guardian_id', 'fk_ctd_guardian')
                ->references('id')->on('creator_guardians')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_ctd_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('file_id', 'fk_ctd_file')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('validated_by_user_id', 'fk_ctd_validator')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }
        Schema::dropIfExists('creator_tax_documents');
        Schema::dropIfExists('creator_payment_methods');
        Schema::dropIfExists('creator_tax_profiles');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['creator_tax_profiles', 'ck_ctp_status', "status IN ('pending','approved','rejected','superseded')", ['status'], 'Estado de perfil tributario no valido.'],
            ['creator_tax_profiles', 'ck_ctp_doc', "issued_document_type IN ('recibo_honorarios','factura','invoice','none')", ['issued_document_type'], 'Documento emitido no valido.'],
            ['creator_tax_profiles', 'ck_ctp_rate', 'withholding_rate >= 0 AND withholding_rate <= 100', ['withholding_rate'], 'La retencion debe estar entre 0 y 100.'],
            // Si hay retención, tiene que haber tasa. Sin esto se guarda
            // "retengo" con tasa cero, que es no retener con otro nombre.
            ['creator_tax_profiles', 'ck_ctp_withholding_status', "withholding_status IN ('pending_review','not_applicable','applies')", ['withholding_status'], 'Estado de retencion no valido.'],
            ['creator_tax_profiles', 'ck_ctp_rate_required', "withholding_status <> 'applies' OR (withholding_rate > 0 AND withholding_basis IS NOT NULL)", ['withholding_status', 'withholding_rate', 'withholding_basis'], 'Si se retiene, hace falta tasa y la norma que la sustenta.'],
            ['creator_tax_profiles', 'ck_ctp_rate_zero', "withholding_status <> 'not_applicable' OR withholding_rate = 0", ['withholding_status', 'withholding_rate'], 'Si no se retiene, la tasa es cero.'],
            // La importante: un perfil no se aprueba con la retención sin decidir.
            ['creator_tax_profiles', 'ck_ctp_withholding_decided', "status <> 'approved' OR withholding_status <> 'pending_review'", ['status', 'withholding_status'], 'No se aprueba un perfil fiscal con la retencion sin decidir (Q-40).'],
            ['creator_tax_profiles', 'ck_ctp_segregation', 'approved_by_user_id IS NULL OR created_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id', ['approved_by_user_id', 'created_by_user_id'], 'Quien captura el dato fiscal no puede aprobarlo.'],
            // BR-CREATOR-007: aprobado exige quién y cuándo. Un "aprobado por
            // nadie" es exactamente lo que un auditor busca.
            ['creator_tax_profiles', 'ck_ctp_approval', "status <> 'approved' OR (approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL)", ['status', 'approved_by_user_id', 'approved_at'], 'Un perfil aprobado exige aprobador y fecha.'],
            ['creator_tax_profiles', 'ck_ctp_dates', 'valid_to IS NULL OR valid_to >= valid_from', ['valid_to', 'valid_from'], 'La vigencia no puede terminar antes de empezar.'],

            ['creator_payment_methods', 'ck_cpm_status', "status IN ('pending','verified','rejected','disabled')", ['status'], 'Estado de medio de pago no valido.'],
            ['creator_payment_methods', 'ck_cpm_method', "method_type IN ('bank_account','wallet','paypal','other')", ['method_type'], 'Tipo de medio de pago no valido.'],
            ['creator_payment_methods', 'ck_cpm_owner', "(owner_type = 'creator' AND owner_guardian_id IS NULL) OR (owner_type = 'guardian' AND owner_guardian_id IS NOT NULL)", ['owner_type', 'owner_guardian_id'], 'El titular declarado no coincide con el tutor asignado.'],
            ['creator_payment_methods', 'ck_cpm_verified', "status <> 'verified' OR (verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL)", ['status', 'verified_at', 'verified_by_user_id'], 'Un medio verificado exige verificador y fecha.'],
            ['creator_payment_methods', 'ck_cpm_masked', 'CHAR_LENGTH(account_number_masked) <= 30', ['account_number_masked'], 'La mascara de cuenta es demasiado larga.'],
            ['creator_payment_methods', 'ck_cpm_fingerprint', 'CHAR_LENGTH(account_number_fingerprint) = 64', ['account_number_fingerprint'], 'La huella de la cuenta debe tener 64 caracteres.'],

            ['creator_tax_documents', 'ck_ctd_status', "status IN ('received','validated','rejected')", ['status'], 'Estado de comprobante no valido.'],
            ['creator_tax_documents', 'ck_ctd_type', "document_type IN ('recibo_honorarios','factura','invoice','other')", ['document_type'], 'Tipo de comprobante no valido.'],
            ['creator_tax_documents', 'ck_ctd_amounts', 'gross_amount >= 0 AND withholding_amount >= 0 AND net_amount >= 0', ['gross_amount', 'withholding_amount', 'net_amount'], 'Los importes no pueden ser negativos.'],
            // La aritmética del comprobante la comprueba la base, no quien teclea.
            ['creator_tax_documents', 'ck_ctd_math', 'net_amount = gross_amount - withholding_amount', ['net_amount', 'gross_amount', 'withholding_amount'], 'El neto no cuadra con el bruto menos la retencion.'],
            ['creator_tax_documents', 'ck_ctd_validated', "status <> 'validated' OR (validated_by_user_id IS NOT NULL AND validated_at IS NOT NULL)", ['status', 'validated_by_user_id', 'validated_at'], 'Un comprobante validado exige validador y fecha.'],
        ];
    }
};
