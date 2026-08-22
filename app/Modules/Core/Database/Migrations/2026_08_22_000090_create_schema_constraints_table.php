<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de las restricciones de integridad declaradas por el esquema.
 *
 * Existe porque en MySQL 5.7 una restriccion puede estar declarada y no existir
 * (DEC-042). Con este registro, 'esquema:verificar' puede comparar lo que el
 * esquema DICE que impone contra lo que el motor impone DE VERDAD, y avisar de
 * cualquier hueco. Sin el, la unica forma de saberlo seria leer las migraciones
 * a mano.
 *
 * Va la primera de todas: App\Shared\Database\Restriccion escribe aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_constraints', function (Blueprint $table): void {
            $table->id();
            $table->string('table_name', 64);
            $table->string('constraint_name', 64);
            $table->text('expression');
            $table->string('columns_involved', 255);
            $table->string('message', 160);
            // 'check' o 'trigger': con que mecanismo se impuso realmente.
            $table->string('mechanism', 10);
            $table->dateTime('created_at', 3)->nullable();

            $table->unique('constraint_name', 'uq_schema_constraints_name');
            $table->index('table_name', 'ix_schema_constraints_table');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_constraints');
    }
};
