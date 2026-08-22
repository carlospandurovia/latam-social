<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos. Se adelanta a la iteración de Creator porque la autorización del
 * tutor y la prueba de parentesco son archivos, y sin esta tabla habría que
 * dejar claves foráneas colgando — que es justo lo que docs/2.3 §9 prohíbe.
 *
 * Las variantes (miniatura, previsualización) van en `file_variants` cuando
 * exista procesamiento de imagen (docs 2.2 P-07). Hoy no lo hay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('disk', 30)->default('s3');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            // Permite detectar duplicados y demostrar que el archivo no se alteró:
            // una evidencia de publicación sin checksum no prueba gran cosa.
            $table->char('checksum_sha256', 64);
            $table->string('visibility', 10)->default('private');
            $table->string('purpose', 40);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->dateTime('purged_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_files_uuid');
            $table->index('checksum_sha256', 'ix_files_checksum');
            $table->index(['purpose', 'created_at'], 'ix_files_purpose');

            $table->foreign('uploaded_by_user_id', 'fk_files_uploader')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'files',
            nombre: 'ck_files_visibility',
            expresion: "visibility IN ('private','public')",
            columnas: ['visibility'],
            mensaje: 'Visibilidad de archivo no valida.',
        );
        Restriccion::comprobacion(
            tabla: 'files',
            nombre: 'ck_files_size',
            expresion: 'size_bytes > 0',
            columnas: ['size_bytes'],
            mensaje: 'Un archivo no puede tener tamano cero.',
        );
    }

    public function down(): void
    {
        Restriccion::quitar('files', 'ck_files_size');
        Restriccion::quitar('files', 'ck_files_visibility');
        Schema::dropIfExists('files');
    }
};
