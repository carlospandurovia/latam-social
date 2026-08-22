<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La solicitud y el creador.
 *
 * Van juntas porque se referencian en las dos direcciones: la solicitud apunta
 * al creador que originó, y el creador a la solicitud de la que salió.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La solicitud es efímera, repetible y rechazable. NO es el creador.
        Schema::create('creator_applications', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('full_name', 160);
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            $table->foreignId('country_id');
            $table->string('source', 20)->default('landing');
            $table->string('referral_code', 30)->nullable();
            $table->string('status', 20)->default('submitted');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->dateTime('reviewed_at', 3)->nullable();
            $table->string('rejection_note', 255)->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->dateTime('submitted_at', 3);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_creator_applications_uuid');
            $table->index(['status', 'submitted_at'], 'ix_creator_applications_status');
            $table->index('country_id', 'ix_creator_applications_country');
            $table->index('referral_code', 'ix_creator_applications_referral');
            $table->index('reviewed_by_user_id', 'ix_creator_applications_reviewer');

            $table->foreign('country_id', 'fk_creator_applications_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('reviewed_by_user_id', 'fk_creator_applications_reviewer')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Se puede volver a postular, pero no tener dos solicitudes abiertas a la
        // vez. Sin índice parcial en MySQL, la columna generada vale NULL cuando
        // la fila deja de contar, y NULL no colisiona en un índice único.
        DB::statement(
            'ALTER TABLE creator_applications ADD COLUMN open_email_key VARCHAR(255) '
            ."GENERATED ALWAYS AS (CASE WHEN status IN ('submitted','in_review') THEN LOWER(email) ELSE NULL END) STORED"
        );
        DB::statement('ALTER TABLE creator_applications ADD UNIQUE KEY uq_creator_applications_open (open_email_key)');

        Schema::create('creators', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            // 1:1 opcional (docs 2.2 P-01): el creador existe antes que su cuenta.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('display_name', 120);
            // Obligatoria (docs 2.3 §3): sin fecha de nacimiento no se puede
            // saber si este creador necesita tutor.
            $table->date('birth_date');
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            $table->foreignId('country_id');
            $table->string('city', 100)->nullable();
            $table->char('document_country_code', 2);
            $table->string('document_type', 20);
            $table->string('document_number', 40);
            $table->string('status', 20)->default('pending');
            // BR-FIN-012: 30 días por defecto, configurable por creador.
            $table->unsignedSmallInteger('payment_term_days')->default(30);
            $table->char('preferred_currency_code', 3);
            $table->string('locale', 10)->default('es');
            $table->string('timezone', 64)->default('America/Lima');
            $table->dateTime('activated_at', 3)->nullable();
            // BR-CREATOR-009: se anonimiza, nunca se borra. Lo financiero queda.
            $table->dateTime('anonymized_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_creators_uuid');
            $table->unique('user_id', 'uq_creators_user');
            $table->index(['status', 'created_at'], 'ix_creators_status');
            $table->index(['country_id', 'status'], 'ix_creators_country');
            $table->index('application_id', 'ix_creators_application');
            $table->index('preferred_currency_code', 'ix_creators_currency');
            $table->index('birth_date', 'ix_creators_birth');

            $table->foreign('user_id', 'fk_creators_user')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('application_id', 'fk_creators_application')
                ->references('id')->on('creator_applications')->restrictOnDelete();
            $table->foreign('country_id', 'fk_creators_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('preferred_currency_code', 'fk_creators_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
        });

        // BR-CREATOR-003: ni dos creadores con el mismo documento, ni con el
        // mismo email. La "puerta" apaga la unicidad al anonimizar, que es
        // cuando esos datos dejan de existir (BR-CREATOR-009).
        //
        // Es una columna puerta y no una clave concatenada a propósito: MariaDB
        // rechaza CONCAT con literales en columnas generadas persistentes, y así
        // el índice recae sobre columnas reales en vez de una cadena inventada.
        DB::statement(
            'ALTER TABLE creators ADD COLUMN identity_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN anonymized_at IS NULL THEN 1 ELSE NULL END) STORED'
        );
        // La intercalación utf8mb4_unicode_ci ya es insensible a mayúsculas:
        // 'ANA@' y 'ana@' colisionan sin necesidad de LOWER().
        DB::statement(
            'ALTER TABLE creators ADD UNIQUE KEY uq_creators_identity '
            .'(identity_gate, document_country_code, document_type, document_number)'
        );
        DB::statement('ALTER TABLE creators ADD UNIQUE KEY uq_creators_email (identity_gate, email)');

        DB::statement(
            'ALTER TABLE creator_applications ADD CONSTRAINT fk_creator_applications_creator '
            .'FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT'
        );

        Restriccion::comprobacion(
            tabla: 'creator_applications',
            nombre: 'ck_creator_applications_status',
            expresion: "status IN ('submitted','in_review','approved','rejected','duplicate')",
            columnas: ['status'],
            mensaje: 'Estado de solicitud no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_applications',
            nombre: 'ck_creator_applications_source',
            expresion: "source IN ('landing','referral','import','manual','event')",
            columnas: ['source'],
            mensaje: 'Origen de solicitud no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_status',
            expresion: "status IN ('pending','active','suspended','rejected','blacklisted','inactive')",
            columnas: ['status'],
            mensaje: 'Estado de creador no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_payment_term',
            expresion: 'payment_term_days BETWEEN 0 AND 180',
            columnas: ['payment_term_days'],
            mensaje: 'El plazo de pago debe estar entre 0 y 180 dias.',
        );
        // Lista cerrada y no catálogo: añadir un tipo de documento ocurre al
        // entrar en un país nuevo, que ya es un despliegue (docs 2.3 §7).
        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_document_type',
            expresion: "document_type IN ('DNI','CE','RUC','PASSPORT','CC','NIT','CURP','RFC','RUT','SSN','NIE','NIF','OTHER')",
            columnas: ['document_type'],
            mensaje: 'Tipo de documento no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_birth_date',
            expresion: "birth_date > '1920-01-01'",
            columnas: ['birth_date'],
            mensaje: 'Fecha de nacimiento no plausible.',
        );
    }

    public function down(): void
    {
        foreach (['ck_creators_birth_date', 'ck_creators_document_type', 'ck_creators_payment_term', 'ck_creators_status'] as $r) {
            Restriccion::quitar('creators', $r);
        }
        foreach (['ck_creator_applications_source', 'ck_creator_applications_status'] as $r) {
            Restriccion::quitar('creator_applications', $r);
        }
        DB::statement('ALTER TABLE creator_applications DROP FOREIGN KEY fk_creator_applications_creator');
        Schema::dropIfExists('creators');
        Schema::dropIfExists('creator_applications');
    }
};
