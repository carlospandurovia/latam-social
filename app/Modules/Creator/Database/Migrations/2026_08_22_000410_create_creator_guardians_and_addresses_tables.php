<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tutela de menores y direcciones.
 *
 * `creator_guardians` es la entidad que faltaba en 2.1 y que cierra el hueco
 * detectado en 2.3 §3: el beneficiario del pago puede no ser el creador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            $table->string('full_name', 160);
            $table->string('relationship', 20);
            $table->char('document_country_code', 2);
            $table->string('document_type', 20);
            $table->string('document_number', 40);
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            // Los dos documentos que exigió el negocio: autorización firmada del
            // padre o tutor, y acreditación del parentesco.
            $table->unsignedBigInteger('authorization_file_id')->nullable();
            $table->unsignedBigInteger('proof_of_relationship_file_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('valid_from');
            // Se rellena con la fecha del 18º cumpleaños (docs 2.3 §3).
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['creator_id', 'status'], 'ix_creator_guardians_creator');
            $table->index('authorization_file_id', 'ix_creator_guardians_auth_file');
            $table->index('proof_of_relationship_file_id', 'ix_creator_guardians_proof_file');

            $table->foreign('creator_id', 'fk_creator_guardians_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('authorization_file_id', 'fk_creator_guardians_auth')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('proof_of_relationship_file_id', 'fk_creator_guardians_proof')
                ->references('id')->on('files')->restrictOnDelete();
        });

        // Un creador no puede tener dos tutelas activas. Las cerradas conviven:
        // el histórico de quién cobró en su nombre no se pierde.
        DB::statement(
            'ALTER TABLE creator_guardians ADD COLUMN active_creator_key BIGINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN creator_id ELSE NULL END) STORED",
        );
        DB::statement('ALTER TABLE creator_guardians ADD UNIQUE KEY uq_creator_guardians_active (active_creator_key)');

        Schema::create('creator_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            // La de envío no es la fiscal, y no siempre coinciden (docs 2.1).
            $table->string('address_type', 15);
            $table->string('line1', 180);
            $table->string('line2', 180)->nullable();
            $table->string('city', 100);
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->foreignId('country_id');
            $table->string('reference_notes', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['creator_id', 'address_type'], 'ix_creator_addresses_creator');
            $table->index('country_id', 'ix_creator_addresses_country');

            $table->foreign('creator_id', 'fk_creator_addresses_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('country_id', 'fk_creator_addresses_country')
                ->references('id')->on('countries')->restrictOnDelete();
        });

        // Una sola dirección por defecto de cada tipo. Un creador puede tener a
        // la vez una de envío y una fiscal marcadas como predeterminadas.
        DB::statement(
            'ALTER TABLE creator_addresses ADD COLUMN default_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE creator_addresses ADD UNIQUE KEY uq_creator_addresses_default '
            .'(default_gate, creator_id, address_type)',
        );

        Restriccion::comprobacion(
            tabla: 'creator_guardians',
            nombre: 'ck_creator_guardians_status',
            expresion: "status IN ('pending','active','closed','revoked')",
            columnas: ['status'],
            mensaje: 'Estado de tutela no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_guardians',
            nombre: 'ck_creator_guardians_relationship',
            expresion: "relationship IN ('father','mother','legal_guardian')",
            columnas: ['relationship'],
            mensaje: 'Parentesco no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_guardians',
            nombre: 'ck_creator_guardians_dates',
            expresion: 'valid_to IS NULL OR valid_to >= valid_from',
            columnas: ['valid_to', 'valid_from'],
            mensaje: 'La vigencia no puede terminar antes de empezar.',
        );
        // La regla que impide pagar a un tutor cuya autorización nadie subió.
        Restriccion::comprobacion(
            tabla: 'creator_guardians',
            nombre: 'ck_creator_guardians_docs',
            expresion: "status <> 'active' OR (authorization_file_id IS NOT NULL AND proof_of_relationship_file_id IS NOT NULL)",
            columnas: ['status', 'authorization_file_id', 'proof_of_relationship_file_id'],
            mensaje: 'Una tutela activa exige autorizacion y prueba de parentesco.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_addresses',
            nombre: 'ck_creator_addresses_type',
            expresion: "address_type IN ('shipping','tax','billing')",
            columnas: ['address_type'],
            mensaje: 'Tipo de direccion no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_addresses',
            nombre: 'ck_creator_addresses_dates',
            expresion: 'valid_to IS NULL OR valid_to >= valid_from',
            columnas: ['valid_to', 'valid_from'],
            mensaje: 'La vigencia no puede terminar antes de empezar.',
        );
    }

    public function down(): void
    {
        foreach (['ck_creator_addresses_dates', 'ck_creator_addresses_type'] as $r) {
            Restriccion::quitar('creator_addresses', $r);
        }
        foreach (['ck_creator_guardians_docs', 'ck_creator_guardians_dates', 'ck_creator_guardians_relationship', 'ck_creator_guardians_status'] as $r) {
            Restriccion::quitar('creator_guardians', $r);
        }
        Schema::dropIfExists('creator_addresses');
        Schema::dropIfExists('creator_guardians');
    }
};
