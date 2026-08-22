<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las tres tablas de solo-inserción del sistema.
 *
 * Ninguna tiene updated_at, y es deliberado: una fila que puede actualizarse no
 * es un hecho, es una opinión. El usuario de aplicación no debe tener UPDATE ni
 * DELETE sobre audit_logs (ver .env.example, DB_MIGRATION_USERNAME).
 */
return new class extends Migration
{
    public function up(): void
    {
        // El registro de hechos del negocio. Es lo que hace que el XP y el
        // Creator Score se puedan recalcular hacia atrás (docs 2.2 P-12).
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('event_name', 80);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->longText('payload')->nullable();
            $table->dateTime('occurred_at', 3);

            $table->unique('uuid', 'uq_domain_events_uuid');
            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'ix_domain_events_entity');
            $table->index(['event_name', 'occurred_at'], 'ix_domain_events_name');
        });

        // Sin FK a users a propósito: un evento sobrevive al usuario que lo causó,
        // y BR-CREATOR-009 puede exigir anonimizar a esa persona sin borrar el hecho.
        // El histórico de estados. Manda sobre la columna vigente (docs 2.3 N-04).
        Schema::create('status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->dateTime('occurred_at', 3);

            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'ix_status_transitions_entity');
        });

        // from_status NULL = alta. Una transición a sí mismo es un error de código.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            // Copia congelada del nombre: si el usuario se anonimiza, el registro
            // debe seguir diciendo algo. Por eso _label y no un JOIN.
            $table->string('actor_label', 120)->nullable();
            $table->string('action', 60);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->longText('changes')->nullable();
            // VARBINARY(16) admite IPv4 e IPv6 con INET6_ATON. Ocupa la mitad
            // que un VARCHAR(45) y no se puede escribir mal.
            $table->binary('ip_address', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('occurred_at', 3);

            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'ix_audit_logs_entity');
            $table->index(['actor_user_id', 'occurred_at'], 'ix_audit_logs_actor');
            // El listado por defecto ordena por `id`: la clave primaria ya es
            // monótona con la inserción y recorrerla sale gratis. Este índice es
            // para FILTRAR por rango de fechas, que sin él escanea la tabla
            // entera — y esta tabla solo crece.
            $table->index('occurred_at', 'ix_audit_logs_occurred');
        });

        Restriccion::comprobacion(
            tabla: 'domain_events',
            nombre: 'ck_domain_events_payload',
            expresion: 'payload IS NULL OR JSON_VALID(payload)',
            columnas: ['payload'],
            mensaje: 'El payload del evento debe ser JSON valido.',
        );
        Restriccion::comprobacion(
            tabla: 'status_transitions',
            nombre: 'ck_status_transitions_change',
            expresion: 'from_status IS NULL OR from_status <> to_status',
            columnas: ['from_status', 'to_status'],
            mensaje: 'Una transicion de estado a si mismo no es una transicion.',
        );
        Restriccion::comprobacion(
            tabla: 'audit_logs',
            nombre: 'ck_audit_logs_changes',
            expresion: 'changes IS NULL OR JSON_VALID(changes)',
            columnas: ['changes'],
            mensaje: 'El detalle de cambios debe ser JSON valido.',
        );

        foreach (self::inmutabilidadDeLaBitacora() as $nombre => $cuerpo) {
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    /**
     * La bitácora no se edita ni se borra desde la aplicación.
     *
     * Regla del cliente: «el registro de auditoría no debe ser fácilmente
     * modificable desde la aplicación». Hasta la iteración 3.2 eso era una
     * intención: la tabla admitía `UPDATE` y `DELETE` como cualquier otra. Una
     * bitácora que la aplicación puede reescribir no es evidencia de nada.
     *
     * Mismo criterio que `ledger_entries`: prohibir un *verbo* no lo puede
     * expresar ningún `CHECK`, así que van disparadores — iguales en los dos
     * motores, sin pasar por el compilador de restricciones.
     *
     * @return array<string, string>
     */
    private static function inmutabilidadDeLaBitacora(): array
    {
        return [
            'tg_audit_no_update' => <<<'SQL'
                BEFORE UPDATE ON `audit_logs`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'audit_logs es solo-insercion: una bitacora que se puede reescribir no es evidencia.';
                END
                SQL,
            'tg_audit_no_delete' => <<<'SQL'
                BEFORE DELETE ON `audit_logs`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'audit_logs no admite borrado. La retencion se aplica por proceso, no con un DELETE.';
                END
                SQL,
        ];
    }

    public function down(): void
    {
        foreach (array_keys(self::inmutabilidadDeLaBitacora()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('status_transitions');
        Schema::dropIfExists('domain_events');
    }
};
