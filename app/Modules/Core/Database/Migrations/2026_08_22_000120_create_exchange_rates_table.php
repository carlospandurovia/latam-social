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
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency_code', 3);
            $table->char('quote_currency_code', 3);
            // DATE, no DATETIME: un tipo de cambio es de un día, no de un instante.
            $table->date('rate_date');
            // 8 decimales: con 4 se redondea mal al convertir importes grandes.
            $table->decimal('rate', 18, 8);
            $table->string('source', 40);
            $table->dateTime('fetched_at', 3);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            // La fuente entra en la clave: BR-FIN-009 exige poder decir de dónde
            // salió la tasa que se aplicó, y dos fuentes pueden discrepar el mismo día.
            $table->unique(
                ['base_currency_code', 'quote_currency_code', 'rate_date', 'source'],
                'uq_exchange_rates',
            );
            $table->index(
                ['base_currency_code', 'quote_currency_code', 'rate_date'],
                'ix_exchange_rates_lookup',
            );

            $table->foreign('base_currency_code', 'fk_exchange_rates_base')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('quote_currency_code', 'fk_exchange_rates_quote')
                ->references('code')->on('currencies')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'exchange_rates',
            nombre: 'ck_exchange_rates_positive',
            expresion: 'rate > 0',
            columnas: ['rate'],
            mensaje: 'El tipo de cambio debe ser mayor que cero.',
        );
        Restriccion::comprobacion(
            tabla: 'exchange_rates',
            nombre: 'ck_exchange_rates_distinct',
            expresion: 'base_currency_code <> quote_currency_code',
            columnas: ['base_currency_code', 'quote_currency_code'],
            mensaje: 'El tipo de cambio necesita dos monedas distintas.',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
