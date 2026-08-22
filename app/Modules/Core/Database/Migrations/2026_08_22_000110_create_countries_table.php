<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->char('iso2', 2);
            $table->char('iso3', 3);
            $table->char('numeric_code', 3);
            $table->string('name', 100);
            $table->string('phone_code', 8);
            $table->char('default_currency_code', 3);
            // La zona horaria del país es lo que convierte un instante UTC en
            // "el día" que exige un comprobante fiscal (docs 2.3 §8).
            $table->string('timezone', 64);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('iso2', 'uq_countries_iso2');
            $table->unique('iso3', 'uq_countries_iso3');
            $table->index(['is_active', 'name'], 'ix_countries_active');

            // RESTRICT por defecto en todo (docs 2.2 §5).
            $table->foreign('default_currency_code', 'fk_countries_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
