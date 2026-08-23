<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los términos y condiciones, versionados (iteración 3.5, DEC-059).
 *
 * `BR-CREATOR-006` exige «aceptación vigente de los términos» desde la
 * iteración 2.1, y hasta aquí **no existía ninguna tabla** donde constara.
 * La condición se enunciaba y no se podía comprobar.
 *
 * La tabla guarda la VERSIÓN, no el texto vivo. Un documento que se edita en su
 * sitio deja todas las aceptaciones anteriores apuntando a algo que ya no
 * existe: quien aceptó en enero aceptó otra cosa. Aquí cada versión es una fila
 * con vigencia propia y su huella `sha256`; publicar la siguiente cierra la
 * anterior, y eso —por sí solo— hace que las aceptaciones viejas dejen de estar
 * vigentes sin que nadie tenga que acordarse de invalidarlas.
 *
 * Va en Core y no en Creator porque el portal de clientes traerá el suyo:
 * `audience` separa a quién obliga cada documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('audience', 20);
            $table->string('code', 40);
            $table->string('version', 20);
            $table->string('title', 160);
            // El texto íntegro, el PDF firmado, o los dos.
            $table->longText('body')->nullable();
            $table->unsignedBigInteger('document_file_id')->nullable();
            // Huella del contenido publicado: si alguien edita el texto después
            // de que lo aceptaran, deja de cuadrar y se nota.
            $table->char('content_sha256', 64);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_terms_versions_uuid');
            $table->unique(['code', 'version'], 'uq_terms_versions_version');
            $table->index(['audience', 'effective_from'], 'ix_terms_versions_audience');
            $table->index('document_file_id', 'ix_terms_versions_file');
            $table->index('published_by_user_id', 'ix_terms_versions_publisher');

            $table->foreign('document_file_id', 'fk_terms_versions_file')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('published_by_user_id', 'fk_terms_versions_publisher')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Una sola versión vigente por documento. Misma técnica que el resto del
        // modelo: la puerta vale NULL cuando la fila deja de contar, y una fila
        // con NULL no colisiona en un índice único.
        DB::statement(
            'ALTER TABLE terms_versions ADD COLUMN current_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement('ALTER TABLE terms_versions ADD UNIQUE KEY uq_terms_versions_current (current_gate, code)');

        Restriccion::comprobacion(
            tabla: 'terms_versions',
            nombre: 'ck_terms_versions_audience',
            expresion: "audience IN ('creator','client')",
            columnas: ['audience'],
            mensaje: 'Publico de los terminos no valido.',
        );
        // Un término sin contenido no se le puede oponer a nadie.
        Restriccion::comprobacion(
            tabla: 'terms_versions',
            nombre: 'ck_terms_versions_content',
            expresion: 'body IS NOT NULL OR document_file_id IS NOT NULL',
            columnas: ['body', 'document_file_id'],
            mensaje: 'Los terminos necesitan texto o documento adjunto.',
        );
        Restriccion::comprobacion(
            tabla: 'terms_versions',
            nombre: 'ck_terms_versions_hash',
            expresion: 'CHAR_LENGTH(content_sha256) = 64',
            columnas: ['content_sha256'],
            mensaje: 'La huella del contenido debe ser un sha256 de 64 caracteres.',
        );
        Restriccion::comprobacion(
            tabla: 'terms_versions',
            nombre: 'ck_terms_versions_dates',
            expresion: 'effective_to IS NULL OR effective_to >= effective_from',
            columnas: ['effective_to', 'effective_from'],
            mensaje: 'La vigencia de los terminos no puede terminar antes de empezar.',
        );
    }

    public function down(): void
    {
        foreach ([
            'ck_terms_versions_dates', 'ck_terms_versions_hash',
            'ck_terms_versions_content', 'ck_terms_versions_audience',
        ] as $r) {
            Restriccion::quitar('terms_versions', $r);
        }

        Schema::dropIfExists('terms_versions');
    }
};
