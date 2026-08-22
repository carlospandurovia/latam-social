<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas sociales y su histórico de métricas.
 *
 * `social_account_snapshots` es de solo inserción: BR-CREATOR-005 prohíbe que
 * un valor nuevo sobrescriba al anterior. Por eso no tiene updated_at, y por eso
 * `creators` no tiene una columna followers_count (eliminada en docs 2.3 §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('creator_id');
            $table->foreignId('platform_id');
            $table->string('handle', 120);
            $table->string('profile_url', 500);
            $table->string('external_id', 120)->nullable();
            $table->string('verification_status', 15)->default('unverified');
            $table->string('verification_method', 20)->nullable();
            $table->dateTime('verified_at', 3)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_social_accounts_uuid');
            $table->unique(['creator_id', 'platform_id', 'handle'], 'uq_social_accounts_creator_handle');
            $table->index(['platform_id', 'verification_status'], 'ix_social_accounts_platform');

            $table->foreign('creator_id', 'fk_social_accounts_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('platform_id', 'fk_social_accounts_platform')
                ->references('id')->on('platforms')->restrictOnDelete();
        });

        // BR-CREATOR-003: la misma cuenta VERIFICADA no puede pertenecer a dos
        // creadores. Sin verificar sí puede repetirse: dos personas pueden
        // reclamar el mismo perfil, y resolverlo es precisamente la verificación.
        DB::statement(
            'ALTER TABLE social_accounts ADD COLUMN verified_gate TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN verification_status = 'verified' THEN 1 ELSE NULL END) STORED",
        );
        DB::statement(
            'ALTER TABLE social_accounts ADD UNIQUE KEY uq_social_accounts_verified '
            .'(verified_gate, platform_id, handle)',
        );
        DB::statement(
            'ALTER TABLE social_accounts ADD COLUMN primary_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN is_primary = 1 THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE social_accounts ADD UNIQUE KEY uq_social_accounts_primary '
            .'(primary_gate, creator_id, platform_id)',
        );

        Schema::create('social_account_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_account_id');
            $table->dateTime('captured_at', 3);
            $table->string('source', 20);
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('following')->nullable();
            $table->unsignedBigInteger('posts_count')->nullable();
            $table->unsignedBigInteger('avg_views')->nullable();
            $table->unsignedBigInteger('avg_likes')->nullable();
            $table->unsignedBigInteger('avg_comments')->nullable();
            $table->decimal('engagement_rate', 7, 4)->nullable();
            // docs 2.2 P-09: columnas para lo que se agrega y grafica, JSON para
            // lo específico de cada red. Ni tabla ancha ni pares métrica-valor.
            $table->longText('extra')->nullable();
            // BR-CREATOR-004: lo anómalo se marca para revisión humana, nunca se
            // rechaza automáticamente. Un creador que crece de golpe puede ser
            // un fraude o puede haberse hecho viral.
            $table->boolean('is_anomalous')->default(false);
            $table->string('anomaly_note', 255)->nullable();

            $table->index(['social_account_id', 'captured_at'], 'ix_sas_account');
            $table->index(['is_anomalous', 'captured_at'], 'ix_sas_anomaly');

            $table->foreign('social_account_id', 'fk_sas_account')
                ->references('id')->on('social_accounts')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'social_accounts',
            nombre: 'ck_social_accounts_verification',
            expresion: "verification_status IN ('unverified','pending','verified','failed')",
            columnas: ['verification_status'],
            mensaje: 'Estado de verificacion no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'social_accounts',
            nombre: 'ck_social_accounts_verified_at',
            expresion: "verification_status <> 'verified' OR verified_at IS NOT NULL",
            columnas: ['verification_status', 'verified_at'],
            mensaje: 'Una cuenta verificada necesita fecha de verificacion.',
        );
        Restriccion::comprobacion(
            tabla: 'social_account_snapshots',
            nombre: 'ck_sas_source',
            expresion: "source IN ('self_declared','api','manual_review','import')",
            columnas: ['source'],
            mensaje: 'Origen de la captura no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'social_account_snapshots',
            nombre: 'ck_sas_engagement',
            expresion: 'engagement_rate IS NULL OR (engagement_rate >= 0 AND engagement_rate <= 100)',
            columnas: ['engagement_rate'],
            mensaje: 'El engagement debe estar entre 0 y 100.',
        );
        Restriccion::comprobacion(
            tabla: 'social_account_snapshots',
            nombre: 'ck_sas_extra',
            expresion: 'extra IS NULL OR JSON_VALID(extra)',
            columnas: ['extra'],
            mensaje: 'El campo extra debe ser JSON valido.',
        );
    }

    public function down(): void
    {
        foreach (['ck_sas_extra', 'ck_sas_engagement', 'ck_sas_source'] as $r) {
            Restriccion::quitar('social_account_snapshots', $r);
        }
        foreach (['ck_social_accounts_verified_at', 'ck_social_accounts_verification'] as $r) {
            Restriccion::quitar('social_accounts', $r);
        }
        Schema::dropIfExists('social_account_snapshots');
        Schema::dropIfExists('social_accounts');
    }
};
