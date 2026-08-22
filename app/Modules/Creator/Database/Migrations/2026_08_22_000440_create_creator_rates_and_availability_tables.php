<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifas declaradas y disponibilidad.
 *
 * BR-CREATOR-008: la tarifa es una REFERENCIA, no un compromiso. Lo vinculante
 * es el monto congelado en la participación de campaña. Por eso aquí no hay
 * aprobaciones ni bloqueos: es información comercial, no un contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            // Sin platform_id: el formato ya pertenece a una red
            // (content_formats.platform_id). Repetirlo aquí sería una dependencia
            // transitiva — exactamente lo que 2.3 se propuso eliminar.
            $table->foreignId('content_format_id');
            $table->char('currency_code', 3);
            $table->decimal('amount', 18, 4);
            $table->string('source', 15)->default('self_declared');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['creator_id', 'content_format_id'], 'ix_creator_rates_creator');
            $table->index(['content_format_id', 'currency_code'], 'ix_creator_rates_format');
            $table->index('currency_code', 'ix_creator_rates_currency');

            $table->foreign('creator_id', 'fk_crate_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('content_format_id', 'fk_crate_format')
                ->references('id')->on('content_formats')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_crate_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
        });

        // Una sola tarifa VIGENTE por formato y moneda. Las cerradas se acumulan
        // como histórico: sirven para ver cómo fue subiendo el precio de alguien.
        DB::statement(
            'ALTER TABLE creator_rates ADD COLUMN current_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE creator_rates ADD UNIQUE KEY uq_creator_rates_current '
            .'(current_gate, creator_id, content_format_id, currency_code)',
        );

        Schema::create('creator_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            $table->boolean('accepts_travel')->default(false);
            $table->string('travel_scope', 15)->nullable();
            $table->boolean('accepts_in_person')->default(true);
            // El negocio decidió que el creador asume el costo de creación; esto
            // registra a quién le vale un canje sin dinero.
            $table->boolean('accepts_product_only')->default(false);
            $table->unsignedSmallInteger('max_campaigns_per_month')->nullable();
            $table->unsignedSmallInteger('min_lead_time_days')->default(3);
            $table->string('notes', 255)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index('creator_id', 'ix_creator_availability_creator');

            $table->foreign('creator_id', 'fk_cav_creator')
                ->references('id')->on('creators')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE creator_availability ADD COLUMN current_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE creator_availability ADD UNIQUE KEY uq_creator_availability_current '
            .'(current_gate, creator_id)',
        );

        Schema::create('creator_blackouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 120)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['creator_id', 'starts_on', 'ends_on'], 'ix_creator_blackouts_creator');

            $table->foreign('creator_id', 'fk_cb_creator')
                ->references('id')->on('creators')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'creator_rates',
            nombre: 'ck_creator_rates_amount',
            expresion: 'amount >= 0',
            columnas: ['amount'],
            mensaje: 'Una tarifa no puede ser negativa.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_rates',
            nombre: 'ck_creator_rates_source',
            expresion: "source IN ('self_declared','negotiated','estimated')",
            columnas: ['source'],
            mensaje: 'Origen de la tarifa no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_rates',
            nombre: 'ck_creator_rates_dates',
            expresion: 'valid_to IS NULL OR valid_to >= valid_from',
            columnas: ['valid_to', 'valid_from'],
            mensaje: 'La vigencia no puede terminar antes de empezar.',
        );

        // Estas dos van SEPARADAS, y no juntas como una sola expresión.
        // La versión de una línea era:
        //     accepts_travel = 0 OR travel_scope IN ('local','national','international')
        // y no funcionaba: con travel_scope NULL, `NULL IN (...)` vale NULL, así
        // que la expresión entera vale NULL — y una restricción solo rechaza lo
        // que evalúa a FALSE, nunca lo que evalúa a NULL. "Viaja, alcance
        // desconocido" pasaba sin resistencia. Hay que preguntar por NULL aparte.
        Restriccion::comprobacion(
            tabla: 'creator_availability',
            nombre: 'ck_creator_availability_scope_values',
            expresion: "travel_scope IS NULL OR travel_scope IN ('local','national','international')",
            columnas: ['travel_scope'],
            mensaje: 'Alcance de viaje no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_availability',
            nombre: 'ck_creator_availability_scope_required',
            expresion: 'accepts_travel = 0 OR travel_scope IS NOT NULL',
            columnas: ['accepts_travel', 'travel_scope'],
            mensaje: 'Si acepta viajar hay que declarar el alcance.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_availability',
            nombre: 'ck_creator_availability_dates',
            expresion: 'valid_to IS NULL OR valid_to >= valid_from',
            columnas: ['valid_to', 'valid_from'],
            mensaje: 'La vigencia no puede terminar antes de empezar.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_blackouts',
            nombre: 'ck_creator_blackouts_dates',
            expresion: 'ends_on >= starts_on',
            columnas: ['ends_on', 'starts_on'],
            mensaje: 'El periodo no puede terminar antes de empezar.',
        );
    }

    public function down(): void
    {
        Restriccion::quitar('creator_blackouts', 'ck_creator_blackouts_dates');
        foreach (['ck_creator_availability_dates', 'ck_creator_availability_scope_required', 'ck_creator_availability_scope_values'] as $r) {
            Restriccion::quitar('creator_availability', $r);
        }
        foreach (['ck_creator_rates_dates', 'ck_creator_rates_source', 'ck_creator_rates_amount'] as $r) {
            Restriccion::quitar('creator_rates', $r);
        }
        Schema::dropIfExists('creator_blackouts');
        Schema::dropIfExists('creator_availability');
        Schema::dropIfExists('creator_rates');
    }
};
