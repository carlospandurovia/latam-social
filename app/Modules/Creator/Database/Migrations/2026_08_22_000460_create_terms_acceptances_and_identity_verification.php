<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos piezas que le faltaban a `BR-CREATOR-006` (iteración 3.5).
 *
 * La regla exige cinco condiciones para activar a un creador. El modelo de
 * datos solo sabía comprobar tres:
 *
 * | Condición                         | Dónde vivía antes de 3.5            |
 * |-----------------------------------|-------------------------------------|
 * | Una red social validada           | `social_accounts.verification_status`|
 * | Datos fiscales del régimen        | `creator_tax_profiles` aprobado      |
 * | Un medio de pago verificado       | `creator_payment_methods` verificado |
 * | **Identidad verificada**          | **en ningún sitio**                  |
 * | **Aceptación vigente de términos**| **en ninguna tabla**                 |
 *
 * Dos de las cinco condiciones no se podían comprobar, así que en la práctica
 * no se exigían. Esta migración las hace comprobables:
 *
 * 1. `terms_acceptances`, solo INSERT, contra la versión de `terms_versions`.
 * 2. Tres columnas de identidad en `creators` —cuándo, quién y con qué
 *    documento—, con un CHECK que obliga a que vayan las tres o ninguna.
 *
 * Se añaden por ALTER en una migración nueva en vez de editar la 000400, que
 * ya se ejecutó. Una migración aplicada no se reescribe: quien ya migró se
 * quedaría con un esquema distinto del que dice el fichero.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------- aceptación de términos
        Schema::create('terms_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->unsignedBigInteger('terms_version_id');
            // Polimórfico como `audit_logs` y `status_transitions`: el mismo
            // documento lo aceptarán creadores y clientes, que son tablas
            // distintas. El precio es que ese id lo sostiene la aplicación.
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            // 'portal' = lo hizo el interesado con su sesión. En todo lo demás
            // hay un revisor que lo registra a partir de una evidencia.
            $table->string('channel', 20);
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->unsignedBigInteger('evidence_file_id')->nullable();
            $table->string('evidence_note', 255)->nullable();
            // Empaquetada, como en la bitácora: 4 bytes IPv4, 16 IPv6.
            $table->binary('ip_address', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('accepted_at', 3);
            // Sin `updated_at`: la tabla es de solo inserción.
            $table->dateTime('created_at', 3)->nullable();

            $table->unique('uuid', 'uq_terms_acceptances_uuid');
            // La misma persona no acepta dos veces la misma versión. Si hay
            // versión nueva, hay fila nueva.
            $table->unique(
                ['subject_type', 'subject_id', 'terms_version_id'],
                'uq_terms_acceptances_subject',
            );
            $table->index(['terms_version_id', 'accepted_at'], 'ix_terms_acceptances_version');
            $table->index('recorded_by_user_id', 'ix_terms_acceptances_recorder');
            $table->index('evidence_file_id', 'ix_terms_acceptances_file');

            $table->foreign('terms_version_id', 'fk_terms_acceptances_version')
                ->references('id')->on('terms_versions')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'fk_terms_acceptances_recorder')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('evidence_file_id', 'fk_terms_acceptances_file')
                ->references('id')->on('files')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'terms_acceptances',
            nombre: 'ck_terms_acceptances_subject',
            expresion: "subject_type IN ('creator','client')",
            columnas: ['subject_type'],
            mensaje: 'Tipo de sujeto no valido para una aceptacion de terminos.',
        );
        Restriccion::comprobacion(
            tabla: 'terms_acceptances',
            nombre: 'ck_terms_acceptances_channel',
            expresion: "channel IN ('portal','email','whatsapp','paper','phone')",
            columnas: ['channel'],
            mensaje: 'Canal de aceptacion no valido.',
        );
        // La regla que convierte «aceptó» en evidencia: si no lo hizo el propio
        // interesado, hay una persona que lo registró y un archivo que lo
        // respalda. Sin esto, «aceptó» es la palabra de quien tecleó.
        Restriccion::comprobacion(
            tabla: 'terms_acceptances',
            nombre: 'ck_terms_acceptances_backing',
            expresion: "channel = 'portal' OR (recorded_by_user_id IS NOT NULL AND evidence_file_id IS NOT NULL)",
            columnas: ['channel', 'recorded_by_user_id', 'evidence_file_id'],
            mensaje: 'Una aceptacion registrada por un tercero exige revisor y evidencia adjunta.',
        );
        // Y en el portal nadie acepta en nombre de otro.
        Restriccion::comprobacion(
            tabla: 'terms_acceptances',
            nombre: 'ck_terms_acceptances_portal',
            expresion: "channel <> 'portal' OR recorded_by_user_id IS NULL",
            columnas: ['channel', 'recorded_by_user_id'],
            mensaje: 'En el portal la aceptacion es del propio interesado.',
        );

        // ------------------------------------------------ identidad verificada
        // Tres columnas y no una casilla: una marca sin quién la puso y sin
        // prueba adjunta no es evidencia de nada (DEC-058).
        Schema::table('creators', function (Blueprint $table): void {
            $table->dateTime('identity_verified_at', 3)->nullable()->after('activated_at');
            $table->unsignedBigInteger('identity_verified_by_user_id')->nullable()->after('identity_verified_at');
            $table->unsignedBigInteger('identity_document_file_id')->nullable()->after('identity_verified_by_user_id');

            $table->index('identity_verified_by_user_id', 'ix_creators_identity_verifier');
            $table->index('identity_document_file_id', 'ix_creators_identity_file');

            $table->foreign('identity_verified_by_user_id', 'fk_creators_identity_verifier')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('identity_document_file_id', 'fk_creators_identity_file')
                ->references('id')->on('files')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_identity_evidence',
            expresion: '(identity_verified_at IS NULL AND identity_verified_by_user_id IS NULL '
                .'AND identity_document_file_id IS NULL) OR (identity_verified_at IS NOT NULL '
                .'AND identity_verified_by_user_id IS NOT NULL AND identity_document_file_id IS NOT NULL)',
            columnas: ['identity_verified_at', 'identity_verified_by_user_id', 'identity_document_file_id'],
            mensaje: 'La verificacion de identidad exige fecha, revisor y documento adjunto.',
        );
        // `activated_at` era decorativa: nada impedía un creador activo sin
        // fecha de activación, y con esa fecha se calcula su antigüedad.
        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_activation',
            expresion: "status <> 'active' OR activated_at IS NOT NULL",
            columnas: ['status', 'activated_at'],
            mensaje: 'Un creador activo necesita fecha de activacion.',
        );
        // De las cinco condiciones de BR-CREATOR-006, esta es la ÚNICA que vive
        // en la propia fila y por tanto la única que la base puede garantizar
        // sola. Que solo se pueda blindar una no es razón para no blindarla:
        // aunque alguien active por SQL saltándose la aplicación, un creador
        // activo sin identidad verificada no entra.
        Restriccion::comprobacion(
            tabla: 'creators',
            nombre: 'ck_creators_active_identity',
            expresion: "status <> 'active' OR identity_verified_at IS NOT NULL",
            columnas: ['status', 'identity_verified_at'],
            mensaje: 'Un creador no se activa sin la identidad verificada (BR-CREATOR-006).',
        );
    }

    public function down(): void
    {
        foreach ([
            'ck_creators_active_identity', 'ck_creators_activation',
            'ck_creators_identity_evidence',
        ] as $r) {
            Restriccion::quitar('creators', $r);
        }

        Schema::table('creators', function (Blueprint $table): void {
            $table->dropForeign('fk_creators_identity_file');
            $table->dropForeign('fk_creators_identity_verifier');
            $table->dropIndex('ix_creators_identity_file');
            $table->dropIndex('ix_creators_identity_verifier');
            $table->dropColumn([
                'identity_document_file_id',
                'identity_verified_by_user_id',
                'identity_verified_at',
            ]);
        });

        foreach ([
            'ck_terms_acceptances_portal', 'ck_terms_acceptances_backing',
            'ck_terms_acceptances_channel', 'ck_terms_acceptances_subject',
        ] as $r) {
            Restriccion::quitar('terms_acceptances', $r);
        }

        Schema::dropIfExists('terms_acceptances');
    }
};
