<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué trabaja el creador: nichos, vetos, formatos e idiomas.
 *
 * Los vetos NO son "lo contrario" de los nichos: llevan motivo y fecha, y
 * alimentan el filtro que evita invitar a alguien a una campaña que rechazaría
 * — que además es una de las señales que erosionan el Creator Score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_categories', function (Blueprint $table): void {
            $table->foreignId('creator_id');
            $table->foreignId('category_id');
            $table->boolean('is_primary')->default(false);
            $table->dateTime('created_at', 3)->nullable();

            $table->primary(['creator_id', 'category_id']);
            $table->index('category_id', 'ix_creator_categories_category');

            $table->foreign('creator_id', 'fk_cc_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('category_id', 'fk_cc_category')
                ->references('id')->on('categories')->restrictOnDelete();
        });

        // Un solo nicho principal por creador: es el que manda al ordenar resultados.
        DB::statement(
            'ALTER TABLE creator_categories ADD COLUMN primary_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN is_primary = 1 THEN 1 ELSE NULL END) STORED',
        );
        DB::statement('ALTER TABLE creator_categories ADD UNIQUE KEY uq_creator_categories_primary (primary_gate, creator_id)');

        Schema::create('creator_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id');
            $table->foreignId('category_id');
            $table->string('reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['creator_id', 'category_id'], 'uq_creator_restrictions');
            $table->index('category_id', 'ix_creator_restrictions_category');

            $table->foreign('creator_id', 'fk_cr_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('category_id', 'fk_cr_category')
                ->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::create('creator_formats', function (Blueprint $table): void {
            $table->foreignId('creator_id');
            $table->foreignId('content_format_id');
            $table->string('experience_level', 15)->default('intermediate');
            $table->boolean('is_offered')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->primary(['creator_id', 'content_format_id']);
            $table->index('content_format_id', 'ix_creator_formats_format');

            $table->foreign('creator_id', 'fk_cf_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('content_format_id', 'fk_cf_format')
                ->references('id')->on('content_formats')->restrictOnDelete();
        });

        Schema::create('creator_languages', function (Blueprint $table): void {
            $table->foreignId('creator_id');
            $table->foreignId('language_id');
            $table->string('proficiency', 15)->default('fluent');
            $table->dateTime('created_at', 3)->nullable();

            $table->primary(['creator_id', 'language_id']);
            $table->index('language_id', 'ix_creator_languages_language');

            $table->foreign('creator_id', 'fk_cl_creator')
                ->references('id')->on('creators')->restrictOnDelete();
            $table->foreign('language_id', 'fk_cl_language')
                ->references('id')->on('languages')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'creator_formats',
            nombre: 'ck_creator_formats_level',
            expresion: "experience_level IN ('beginner','intermediate','expert')",
            columnas: ['experience_level'],
            mensaje: 'Nivel de experiencia no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'creator_languages',
            nombre: 'ck_creator_languages_proficiency',
            expresion: "proficiency IN ('native','fluent','intermediate','basic')",
            columnas: ['proficiency'],
            mensaje: 'Nivel de idioma no valido.',
        );
    }

    public function down(): void
    {
        Restriccion::quitar('creator_languages', 'ck_creator_languages_proficiency');
        Restriccion::quitar('creator_formats', 'ck_creator_formats_level');
        Schema::dropIfExists('creator_languages');
        Schema::dropIfExists('creator_formats');
        Schema::dropIfExists('creator_restrictions');
        Schema::dropIfExists('creator_categories');
    }
};
