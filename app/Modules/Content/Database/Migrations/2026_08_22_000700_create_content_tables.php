<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contenido, publicación y evidencia.
 *
 * Este es el módulo que resuelve el dolor número dos del negocio: **los posts
 * se borran**. La marca paga por una publicación y a los tres meses no queda
 * rastro. Aquí queda: evidencia con checksum y comprobaciones de permanencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cuelga de la participación (docs 2.2 P-03), no de la campaña: dos
        // creadores de la misma campaña pueden tener entregables distintos si
        // están en mercados distintos.
        Schema::create('deliverables', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('campaign_creator_id');
            $table->foreignId('campaign_requirement_id');
            // Un requisito de 3 reels produce 3 entregables numerados.
            $table->unsignedSmallInteger('sequence_number')->default(1);
            $table->string('status', 20)->default('pending');
            $table->date('due_on');
            $table->dateTime('submitted_at', 3)->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_del_uuid');
            $table->unique(['campaign_creator_id', 'campaign_requirement_id', 'sequence_number'], 'uq_del_sequence');
            $table->index(['campaign_creator_id', 'status'], 'ix_del_participation');
            $table->index('campaign_requirement_id', 'ix_del_requirement');
            $table->index(['due_on', 'status'], 'ix_del_due');

            $table->foreign('campaign_creator_id', 'fk_del_participation')
                ->references('id')->on('campaign_creators')->restrictOnDelete();
            $table->foreign('campaign_requirement_id', 'fk_del_requirement')
                ->references('id')->on('campaign_requirements')->restrictOnDelete();
        });

        // Solo inserción: cada reenvío es una versión nueva, nunca una
        // sobrescritura. Es lo que permite responder "cuántas vueltas costó
        // esto", que alimenta el Creator Score y el conteo de rondas incluidas.
        Schema::create('deliverable_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('deliverable_id');
            $table->unsignedSmallInteger('version_number');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->longText('caption')->nullable();
            $table->string('creator_notes', 500)->nullable();
            $table->dateTime('submitted_at', 3);

            $table->unique('uuid', 'uq_dv_uuid');
            $table->unique(['deliverable_id', 'version_number'], 'uq_dv_number');
            $table->index(['deliverable_id', 'submitted_at'], 'ix_dv_deliverable');
            $table->index('file_id', 'ix_dv_file');

            $table->foreign('deliverable_id', 'fk_dv_deliverable')
                ->references('id')->on('deliverables')->restrictOnDelete();
            $table->foreign('file_id', 'fk_dv_file')
                ->references('id')->on('files')->restrictOnDelete();
        });

        // Solo inserción: un veredicto no se edita, se emite otro.
        Schema::create('content_reviews', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('deliverable_version_id');
            $table->unsignedBigInteger('reviewer_user_id')->nullable();
            // Quién revisa cambia quién consume ronda de las incluidas.
            $table->string('reviewer_side', 10)->default('platform');
            $table->string('outcome', 20);
            $table->longText('comments')->nullable();
            $table->boolean('consumes_round')->default(false);
            $table->dateTime('reviewed_at', 3);

            $table->unique('uuid', 'uq_cvw_uuid');
            $table->index(['deliverable_version_id', 'reviewed_at'], 'ix_cvw_version');
            $table->index('reviewer_user_id', 'ix_cvw_reviewer');

            $table->foreign('deliverable_version_id', 'fk_cvw_version')
                ->references('id')->on('deliverable_versions')->restrictOnDelete();
            $table->foreign('reviewer_user_id', 'fk_cvw_reviewer')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // El negocio lo pidió explícito: el creador adjunta el enlace publicado
        // y la aplicación debe poder validar que es de la red que dice
        // (platforms.url_pattern, iteración 2.6).
        Schema::create('publications', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('deliverable_id');
            $table->foreignId('platform_id');
            $table->string('url', 500);
            // URL normalizada (sin utm ni parámetros de campaña) y hasheada,
            // para detectar que dos creadores reclaman el MISMO post. La URL
            // cruda puede pasar de 500 caracteres; el hash siempre mide igual
            // y se indexa bien.
            $table->char('url_fingerprint', 64);
            $table->string('external_post_id', 120)->nullable();
            $table->dateTime('published_at', 3);
            // published_at + permanence_days del requisito, calculado al verificar.
            $table->date('permanence_until')->nullable();
            $table->string('status', 20)->default('reported');
            $table->dateTime('verified_at', 3)->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->dateTime('removed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_pub_uuid');
            // El mismo post no puede reclamarse dos veces.
            $table->unique('url_fingerprint', 'uq_pub_fingerprint');
            $table->index(['deliverable_id', 'status'], 'ix_pub_deliverable');
            $table->index(['platform_id', 'published_at'], 'ix_pub_platform');
            $table->index(['permanence_until', 'status'], 'ix_pub_permanence');
            $table->index('verified_by_user_id', 'ix_pub_verifier');

            $table->foreign('deliverable_id', 'fk_pub_deliverable')
                ->references('id')->on('deliverables')->restrictOnDelete();
            $table->foreign('platform_id', 'fk_pub_platform')
                ->references('id')->on('platforms')->restrictOnDelete();
            $table->foreign('verified_by_user_id', 'fk_pub_verifier')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Solo inserción y con checksum en el archivo: los posts se borran, y
        // esto es lo único que le queda a la marca para demostrar que la
        // campaña se ejecutó.
        Schema::create('publication_evidence', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('publication_id');
            $table->string('evidence_type', 20);
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->dateTime('captured_at', 3);
            $table->unsignedBigInteger('captured_by_user_id')->nullable();

            $table->unique('uuid', 'uq_pev_uuid');
            $table->index(['publication_id', 'captured_at'], 'ix_pev_publication');
            $table->index('file_id', 'ix_pev_file');
            $table->index('captured_by_user_id', 'ix_pev_user');

            $table->foreign('publication_id', 'fk_pev_publication')
                ->references('id')->on('publications')->restrictOnDelete();
            $table->foreign('file_id', 'fk_pev_file')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('captured_by_user_id', 'fk_pev_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Alimenta el evento PermanenceCheckPassed que docs 2.2 P-12 marcó como
        // pendiente de crear. Solo inserción.
        Schema::create('permanence_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_id');
            $table->dateTime('checked_at', 3);
            $table->boolean('is_live');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->string('notes', 255)->nullable();

            $table->index(['publication_id', 'checked_at'], 'ix_pc_publication');
            $table->index(['is_live', 'checked_at'], 'ix_pc_live');
            $table->index('evidence_id', 'ix_pc_evidence');

            $table->foreign('publication_id', 'fk_pc_publication')
                ->references('id')->on('publications')->restrictOnDelete();
            $table->foreign('evidence_id', 'fk_pc_evidence')
                ->references('id')->on('publication_evidence')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }
        Schema::dropIfExists('permanence_checks');
        Schema::dropIfExists('publication_evidence');
        Schema::dropIfExists('publications');
        Schema::dropIfExists('content_reviews');
        Schema::dropIfExists('deliverable_versions');
        Schema::dropIfExists('deliverables');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['deliverables', 'ck_del_status', "status IN ('pending','in_production','submitted','in_review','changes_requested','approved','published','verified','cancelled')", ['status'], 'Estado de entregable no valido.'],
            ['deliverables', 'ck_del_sequence', 'sequence_number >= 1', ['sequence_number'], 'La numeracion empieza en 1.'],
            ['deliverables', 'ck_del_approved', 'approved_at IS NULL OR submitted_at IS NOT NULL', ['approved_at', 'submitted_at'], 'No se puede aprobar algo que no se entrego.'],
            ['deliverable_versions', 'ck_dv_number', 'version_number >= 1', ['version_number'], 'La version empieza en 1.'],
            ['deliverable_versions', 'ck_dv_content', 'file_id IS NOT NULL OR external_url IS NOT NULL', ['file_id', 'external_url'], 'Una version necesita archivo o enlace.'],
            ['content_reviews', 'ck_cvw_outcome', "outcome IN ('approved','changes_requested','rejected')", ['outcome'], 'Veredicto de revision no valido.'],
            ['content_reviews', 'ck_cvw_side', "reviewer_side IN ('platform','client')", ['reviewer_side'], 'Lado revisor no valido.'],
            // Una aprobación no puede gastar una de las 2 rondas incluidas en
            // el precio. Solo la corrección.
            ['content_reviews', 'ck_cvw_round', "consumes_round = 0 OR outcome = 'changes_requested'", ['consumes_round', 'outcome'], 'Solo una correccion consume ronda.'],
            ['publications', 'ck_pub_status', "status IN ('reported','verified','rejected','removed','expired')", ['status'], 'Estado de publicacion no valido.'],
            ['publications', 'ck_pub_verified', "status <> 'verified' OR (verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL)", ['status', 'verified_at', 'verified_by_user_id'], 'Una publicacion verificada exige verificador y fecha.'],
            ['publications', 'ck_pub_removed', "status <> 'removed' OR removed_at IS NOT NULL", ['status', 'removed_at'], 'Una publicacion retirada exige su fecha.'],
            ['publications', 'ck_pub_fingerprint', 'CHAR_LENGTH(url_fingerprint) = 64', ['url_fingerprint'], 'La huella de la URL debe tener 64 caracteres.'],
            ['publication_evidence', 'ck_pev_type', "evidence_type IN ('screenshot','api_snapshot','http_check','archive','manual')", ['evidence_type'], 'Tipo de evidencia no valido.'],
            ['publication_evidence', 'ck_pev_payload', 'raw_payload IS NULL OR JSON_VALID(raw_payload)', ['raw_payload'], 'El payload debe ser JSON valido.'],
            // Una evidencia sin nada que enseñar no es evidencia.
            ['publication_evidence', 'ck_pev_content', 'file_id IS NOT NULL OR raw_payload IS NOT NULL OR http_status IS NOT NULL', ['file_id', 'raw_payload', 'http_status'], 'Una evidencia sin contenido no es evidencia.'],
        ];
    }
};
