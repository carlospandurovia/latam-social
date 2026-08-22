<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La campaña. El corazón del sistema.
 *
 * Lo importante no son las columnas sino dónde vive cada verdad:
 *  - El compromiso económico se CONGELA en `campaign_creators` (BR-CREATOR-008).
 *  - El porqué de cada cambio vive en `agreement_amendments`, append-only.
 *  - El costo de creadores es SUMA de participaciones y el margen es caché
 *    reconstruible (docs 2.3 §5): ninguno de los dos es columna aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('code', 20);
            $table->string('name', 180);
            $table->foreignId('client_organization_id');
            $table->foreignId('client_brand_id');
            $table->string('objective', 30)->default('awareness');
            $table->longText('briefing')->nullable();
            $table->unsignedBigInteger('briefing_file_id')->nullable();
            $table->string('status', 20)->default('draft');
            // Lo que se cobra al cliente. El costo de creadores NO va aquí: se
            // eliminó en docs 2.3 §4 por duplicar verdad viva.
            $table->decimal('revenue_amount', 18, 4)->default(0);
            $table->char('currency_code', 3);
            // El negocio lo fijó: 2 rondas de corrección incluidas en el precio.
            $table->unsignedTinyInteger('included_revision_rounds')->default(2);
            // BR-CREATOR-012. Por defecto hereda de las categorías del brief,
            // pero se puede endurecer por campaña.
            $table->unsignedTinyInteger('min_creator_age')->default(0);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('publication_deadline')->nullable();
            $table->dateTime('confirmed_at', 3)->nullable();
            $table->dateTime('closed_at', 3)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_camp_uuid');
            $table->unique('code', 'uq_camp_code');
            $table->index(['client_organization_id', 'status'], 'ix_camp_client');
            $table->index(['client_brand_id', 'status'], 'ix_camp_brand');
            $table->index(['status', 'starts_on'], 'ix_camp_status');
            $table->index('currency_code', 'ix_camp_currency');
            $table->index('created_by_user_id', 'ix_camp_creator_user');
            $table->index('briefing_file_id', 'ix_camp_file');

            $table->foreign('client_organization_id', 'fk_camp_client')
                ->references('id')->on('client_organizations')->restrictOnDelete();
            $table->foreign('client_brand_id', 'fk_camp_brand')
                ->references('id')->on('client_brands')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_camp_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'fk_camp_user')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('briefing_file_id', 'fk_camp_file')
                ->references('id')->on('files')->restrictOnDelete();
        });

        Schema::create('campaign_markets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id');
            $table->foreignId('country_id');
            $table->unsignedSmallInteger('target_creators')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['campaign_id', 'country_id'], 'uq_cm_campaign_country');
            $table->index('country_id', 'ix_cm_country');

            $table->foreign('campaign_id', 'fk_cm_campaign')
                ->references('id')->on('campaigns')->restrictOnDelete();
            $table->foreign('country_id', 'fk_cm_country')
                ->references('id')->on('countries')->restrictOnDelete();
        });

        // docs 2.3 N-03: campaign_market_id NULL = todos los mercados. Si existe
        // alguno específico para un mercado, REEMPLAZA al general para ese
        // mercado; no se mezclan. Fusionar obligaría a decidir si "3 Stories
        // generales + 2 de México" son 2, 3 o 5, y eso se descubre en producción.
        Schema::create('campaign_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id');
            $table->unsignedBigInteger('campaign_market_id')->nullable();
            $table->foreignId('content_format_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('deadline_offset_days')->default(7);
            // Cuánto debe seguir publicado. El negocio lo pidió por campaña y red.
            $table->unsignedSmallInteger('permanence_days')->default(30);
            $table->string('notes', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            // Cubre los requisitos DE MERCADO. No cubre los generales, y ese es
            // el punto siguiente.
            $table->unique(['campaign_id', 'campaign_market_id', 'content_format_id'], 'uq_creq_market');
            $table->index('campaign_market_id', 'ix_creq_market');
            $table->index('content_format_id', 'ix_creq_format');

            $table->foreign('campaign_id', 'fk_creq_campaign')
                ->references('id')->on('campaigns')->restrictOnDelete();
            $table->foreign('campaign_market_id', 'fk_creq_market')
                ->references('id')->on('campaign_markets')->restrictOnDelete();
            $table->foreign('content_format_id', 'fk_creq_format')
                ->references('id')->on('content_formats')->restrictOnDelete();
        });

        // Con campaign_market_id NULL, el índice único de arriba NO se aplica:
        // NULL no colisiona con NULL. Es el mismo comportamiento que aprovecho a
        // propósito en las columnas puerta, y aquí juega en contra, porque este
        // es el único sitio del modelo donde NULL SIGNIFICA algo ("todos los
        // mercados", docs 2.3 §9) en vez de "no aplica". Este índice es el precio
        // de aquella excepción: invierte la puerta y cubre justo ese hueco.
        DB::statement(
            'ALTER TABLE campaign_requirements ADD COLUMN general_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN campaign_market_id IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE campaign_requirements ADD UNIQUE KEY uq_creq_general '
            .'(general_gate, campaign_id, content_format_id)',
        );

        Schema::create('campaign_creators', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('campaign_id');
            $table->foreignId('creator_id');
            $table->unsignedBigInteger('campaign_market_id')->nullable();
            $table->string('status', 20)->default('shortlisted');
            // BR-CREATOR-008: la tarifa declarada es referencia; ESTO es el
            // compromiso. La tarifa sube; lo pactado no.
            $table->decimal('agreed_amount', 18, 4)->default(0);
            $table->char('currency_code', 3);
            // docs 2.3 §3: el beneficiario se congela AL ACEPTAR, no al pagar.
            // Si el creador cumple 18 a mitad de campaña, cobra quien firmó.
            $table->string('payee_type', 10)->default('creator');
            $table->unsignedBigInteger('payee_guardian_id')->nullable();
            // BR-FIN-012: el plazo también se congela, para que cambiarlo después
            // no altere lo prometido a quien ya aceptó.
            $table->unsignedSmallInteger('payment_term_days_snapshot')->default(30);
            $table->unsignedTinyInteger('revision_rounds_used')->default(0);
            $table->dateTime('invited_at', 3)->nullable();
            $table->dateTime('accepted_at', 3)->nullable();
            $table->dateTime('declined_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_ccr_uuid');
            $table->unique(['campaign_id', 'creator_id'], 'uq_ccr_campaign_creator');
            $table->index(['campaign_id', 'status'], 'ix_ccr_campaign_status');
            $table->index(['creator_id', 'status'], 'ix_ccr_creator');
            $table->index('campaign_market_id', 'ix_ccr_market');
            $table->index('payee_guardian_id', 'ix_ccr_guardian');
            $table->index('currency_code', 'ix_ccr_currency');

            $table->foreign('campaign_id', 'fk_ccr_campaign')
                ->references('id')->on('campaigns')->restrictOnDelete();
            $table->foreign('creator_id', 'fk_ccr_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('campaign_market_id', 'fk_ccr_market')
                ->references('id')->on('campaign_markets')->restrictOnDelete();
            $table->foreign('payee_guardian_id', 'fk_ccr_guardian')
                ->references('id')->on('creator_guardians')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_ccr_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
        });

        // docs 2.2 P-04: la invitación es entidad propia. Se envía, expira, se
        // reenvía por otro canal. Cuántas veces hubo que insistirle a alguien es
        // una de las señales que alimentan el Creator Score.
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('campaign_creator_id');
            $table->string('channel', 15)->default('email');
            // Se guarda el HASH del token del enlace firmado, nunca el token:
            // quien lea la base no debe poder responder por el creador.
            $table->char('token_hash', 64);
            $table->dateTime('sent_at', 3);
            $table->dateTime('expires_at', 3);
            $table->dateTime('opened_at', 3)->nullable();
            $table->dateTime('responded_at', 3)->nullable();
            $table->string('response', 10)->nullable();
            $table->dateTime('created_at', 3)->nullable();

            $table->unique('uuid', 'uq_inv_uuid');
            $table->unique('token_hash', 'uq_inv_token');
            $table->index(['campaign_creator_id', 'sent_at'], 'ix_inv_participation');
            $table->index(['expires_at', 'responded_at'], 'ix_inv_expires');

            $table->foreign('campaign_creator_id', 'fk_inv_participation')
                ->references('id')->on('campaign_creators')->restrictOnDelete();
        });

        // BR-CAMPAIGN-003: cambiar monto, entregables o fechas es una ENMIENDA
        // que las dos partes aceptan. Append-only: el valor vigente vive en la
        // participación, el porqué vive aquí (docs 2.2 P-08).
        Schema::create('agreement_amendments', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('campaign_creator_id');
            $table->string('field', 40);
            $table->string('old_value', 255)->nullable();
            $table->string('new_value', 255);
            $table->string('reason', 255)->nullable();
            $table->string('proposed_by', 10);
            $table->unsignedBigInteger('proposed_by_user_id')->nullable();
            $table->dateTime('proposed_at', 3);
            $table->dateTime('accepted_at', 3)->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->dateTime('rejected_at', 3)->nullable();

            $table->unique('uuid', 'uq_aa_uuid');
            $table->index(['campaign_creator_id', 'proposed_at'], 'ix_aa_participation');
            $table->index('proposed_by_user_id', 'ix_aa_proposer');
            $table->index('accepted_by_user_id', 'ix_aa_accepter');

            $table->foreign('campaign_creator_id', 'fk_aa_participation')
                ->references('id')->on('campaign_creators')->restrictOnDelete();
            $table->foreign('proposed_by_user_id', 'fk_aa_proposer')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('accepted_by_user_id', 'fk_aa_accepter')
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
        Schema::dropIfExists('agreement_amendments');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('campaign_creators');
        Schema::dropIfExists('campaign_requirements');
        Schema::dropIfExists('campaign_markets');
        Schema::dropIfExists('campaigns');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['campaigns', 'ck_camp_status', "status IN ('draft','pending_approval','approved','recruiting','in_progress','in_review','completed','cancelled')", ['status'], 'Estado de campana no valido.'],
            ['campaigns', 'ck_camp_objective', "objective IN ('awareness','consideration','conversion','ugc','launch','event')", ['objective'], 'Objetivo de campana no valido.'],
            ['campaigns', 'ck_camp_dates', 'ends_on >= starts_on', ['ends_on', 'starts_on'], 'La campana no puede terminar antes de empezar.'],
            ['campaigns', 'ck_camp_revenue', 'revenue_amount >= 0', ['revenue_amount'], 'El ingreso no puede ser negativo.'],
            ['campaigns', 'ck_camp_rounds', 'included_revision_rounds BETWEEN 0 AND 10', ['included_revision_rounds'], 'Las rondas incluidas deben estar entre 0 y 10.'],
            // A partir de aprobada, la campaña ya no se puede borrar (docs 2.2 §5).
            // La fecha de confirmación es el instante exacto de esa frontera.
            ['campaigns', 'ck_camp_confirmed', "status IN ('draft','pending_approval','cancelled') OR confirmed_at IS NOT NULL", ['status', 'confirmed_at'], 'Una campana confirmada exige fecha de confirmacion.'],
            ['campaign_requirements', 'ck_creq_quantity', 'quantity >= 1', ['quantity'], 'La cantidad minima es 1.'],
            ['campaign_creators', 'ck_ccr_status', "status IN ('shortlisted','invited','accepted','declined','expired','in_production','delivered','approved','published','verified','completed','cancelled')", ['status'], 'Estado de participacion no valido.'],
            ['campaign_creators', 'ck_ccr_amount', 'agreed_amount >= 0', ['agreed_amount'], 'El monto acordado no puede ser negativo.'],
            ['campaign_creators', 'ck_ccr_payee', "(payee_type = 'creator' AND payee_guardian_id IS NULL) OR (payee_type = 'guardian' AND payee_guardian_id IS NOT NULL)", ['payee_type', 'payee_guardian_id'], 'El beneficiario declarado no coincide con el tutor asignado.'],
            ['campaign_creators', 'ck_ccr_accepted', "status IN ('shortlisted','invited','declined','expired','cancelled') OR accepted_at IS NOT NULL", ['status', 'accepted_at'], 'Una participacion aceptada exige fecha de aceptacion.'],
            ['campaign_creators', 'ck_ccr_declined', "status <> 'declined' OR declined_at IS NOT NULL", ['status', 'declined_at'], 'Un rechazo exige su fecha.'],
            ['invitations', 'ck_inv_channel', "channel IN ('email','whatsapp','sms','in_app','manual')", ['channel'], 'Canal de invitacion no valido.'],
            ['invitations', 'ck_inv_response', "response IS NULL OR response IN ('accepted','declined')", ['response'], 'Respuesta de invitacion no valida.'],
            ['invitations', 'ck_inv_dates', 'expires_at > sent_at', ['expires_at', 'sent_at'], 'La invitacion no puede expirar antes de enviarse.'],
            // Respuesta y fecha van juntas o no van. Una respuesta sin fecha deja
            // sin saber si el creador tardó un día o tres semanas, que es
            // exactamente lo que mide el Creator Score.
            ['invitations', 'ck_inv_responded', '(response IS NULL) = (responded_at IS NULL)', ['response', 'responded_at'], 'La respuesta y su fecha van juntas o no van.'],
            ['agreement_amendments', 'ck_aa_field', "field IN ('agreed_amount','deliverables','deadline','permanence','other')", ['field'], 'Campo de enmienda no valido.'],
            ['agreement_amendments', 'ck_aa_proposer', "proposed_by IN ('platform','creator','client')", ['proposed_by'], 'Proponente no valido.'],
            ['agreement_amendments', 'ck_aa_outcome', 'accepted_at IS NULL OR rejected_at IS NULL', ['accepted_at', 'rejected_at'], 'Una enmienda no puede estar aceptada y rechazada.'],
            ['agreement_amendments', 'ck_aa_accepted', 'accepted_at IS NULL OR accepted_by_user_id IS NOT NULL', ['accepted_at', 'accepted_by_user_id'], 'Una enmienda aceptada exige quien la acepto.'],
        ];
    }
};
