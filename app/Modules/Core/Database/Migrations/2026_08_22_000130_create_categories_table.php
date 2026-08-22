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
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('code', 60);
            $table->unsignedTinyInteger('depth')->default(0);
            // BR-CREATOR-012: edad mínima por categoría. Los valores concretos
            // siguen abiertos en Q-37; la columna existe para poder aplicarlos.
            $table->unsignedTinyInteger('min_age')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_categories_code');
            $table->index('parent_id', 'ix_categories_parent');

            $table->foreign('parent_id', 'fk_categories_parent')
                ->references('id')->on('categories')->restrictOnDelete();
        });

        // Dos niveles y basta (docs 2.2 P-10). La restricción lo impone la base,
        // no una validación de PHP que dos peticiones simultáneas pueden esquivar.
        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id');
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['category_id', 'locale'], 'uq_category_translations');

            // CASCADE legítimo: una traducción no significa nada sin su categoría.
            $table->foreign('category_id', 'fk_category_translations_category')
                ->references('id')->on('categories')->cascadeOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'categories',
            nombre: 'ck_categories_depth',
            expresion: 'depth <= 1',
            columnas: ['depth'],
            mensaje: 'Solo se admiten dos niveles de categoria.',
        );
        Restriccion::comprobacion(
            tabla: 'categories',
            nombre: 'ck_categories_root',
            expresion: '(depth = 0 AND parent_id IS NULL) OR (depth = 1 AND parent_id IS NOT NULL)',
            columnas: ['depth', 'parent_id'],
            mensaje: 'Una categoria raiz no tiene padre; un subnicho si.',
        );
        Restriccion::comprobacion(
            tabla: 'categories',
            nombre: 'ck_categories_min_age',
            expresion: 'min_age <= 21',
            columnas: ['min_age'],
            mensaje: 'La edad minima de una categoria no puede superar 21.',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');
    }
};
