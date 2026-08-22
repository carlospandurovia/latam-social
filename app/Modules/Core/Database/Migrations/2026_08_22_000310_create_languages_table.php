<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            // ISO 639-1, con margen para variantes regionales ('pt-BR').
            $table->string('code', 10);
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_languages_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
