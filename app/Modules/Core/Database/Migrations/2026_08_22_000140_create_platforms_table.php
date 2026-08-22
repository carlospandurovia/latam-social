<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 60);
            // Patrón para validar que el enlace que sube el creador es de esta red.
            // El negocio pidió que "la aplicación sea capaz de validar el enlace".
            $table->string('url_pattern', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_platforms_code');
        });

        Schema::create('content_formats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id');
            $table->string('code', 40);
            // Permanencia por defecto del post. El valor efectivo lo fija cada
            // CampaignRequirement (docs 2.3 N-03); esto es solo la sugerencia.
            $table->unsignedSmallInteger('default_permanence_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['platform_id', 'code'], 'uq_content_formats');

            $table->foreign('platform_id', 'fk_content_formats_platform')
                ->references('id')->on('platforms')->restrictOnDelete();
        });

        Schema::create('content_format_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_format_id');
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['content_format_id', 'locale'], 'uq_content_format_translations');

            $table->foreign('content_format_id', 'fk_cft_format')
                ->references('id')->on('content_formats')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_format_translations');
        Schema::dropIfExists('content_formats');
        Schema::dropIfExists('platforms');
    }
};
