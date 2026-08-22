<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 3);
            $table->string('name', 60);
            $table->string('symbol', 8);
            // BR-FIN-004: el número de decimales es del dinero, no de la pantalla.
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            // DATETIME(3) y no TIMESTAMP: TIMESTAMP convierte según la zona de la
            // sesión y muere en 2038. Guardamos UTC explícito (docs 2.4 §5).
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_currencies_code');
        });

        Restriccion::comprobacion(
            tabla: 'currencies',
            nombre: 'ck_currencies_decimals',
            expresion: 'decimal_places <= 4',
            columnas: ['decimal_places'],
            mensaje: 'Una moneda no puede tener mas de 4 decimales.',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
