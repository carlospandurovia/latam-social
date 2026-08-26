<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las preguntas del creador antes de contestar una invitación (`T-38`).
 *
 * ### Por qué esto no es cosmético
 *
 * `7.6` dejó al creador con dos botones: aceptar o rechazar, y el rechazo pide un
 * motivo de lista cerrada para poder contestar *«¿por qué nos dicen que no?»*.
 *
 * Sin un sitio donde preguntar, **una duda se convierte en un rechazo**. Y ese
 * rechazo entra en `decline_reason` como si fuera una opinión sobre la oferta:
 * *«no me cuadran las fechas»* cuando lo que pasaba es que no sabía si el envío
 * del producto llegaba antes. La estadística que `7.6` existe para producir
 * quedaría contaminada por gente que no tenía a quién preguntar.
 *
 * ### Tabla propia, no una columna en `invitations`
 *
 * Porque se puede preguntar más de una vez, y porque cada pregunta tiene su
 * momento y su origen. Una columna obligaría a machacar la anterior o a
 * concatenar texto, que es la forma de perder la primera.
 *
 * ### Lo que NO hay aquí, y es deliberado
 *
 * **No hay respuesta.** No existe `answer`, ni `answered_by_user_id`, ni un hilo.
 * El equipo contesta por correo, que es donde el creador ya está. Un hilo de ida
 * y vuelta dentro de la aplicación es un módulo de mensajería —con notificaciones,
 * no leídos y permisos— y meterlo aquí habría multiplicado por tres esta
 * iteración para resolver un problema que un `Responder` ya resuelve.
 *
 * Cuando haya volumen real de invitaciones se verá si hace falta. Mientras tanto
 * la pregunta **consta**, que es lo que faltaba.
 *
 * ### Preguntar NO mueve el plazo
 *
 * Decisión de negocio (2026-08-26). La alternativa —congelar la invitación hasta
 * que alguien conteste— deja el importe comprometido para siempre si nadie
 * contesta, que es justo el agujero que `invitaciones:caducar` existe para tapar.
 * La pantalla se lo dice al creador, y quien invitó ve la pregunta y decide: si
 * hace falta más tiempo, anula y manda otra invitación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_questions', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->unsignedBigInteger('invitation_id');
            $table->string('body', 1000);
            $table->dateTime('asked_at', 3);
            // Desde donde. La misma logica que `invitations.responded_ip`: lo que
            // llega por un enlace publico se registra con su origen.
            $table->binary('asked_ip', 16)->nullable();
            // Quien la vio por dentro. No es una respuesta: es «alguien del
            // equipo se hizo cargo», que es lo que hace falta para que una
            // pregunta no se quede huerfana en una lista.
            $table->dateTime('seen_at', 3)->nullable();
            $table->unsignedBigInteger('seen_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_iq_uuid');
            $table->index(['invitation_id', 'asked_at'], 'ix_iq_invitacion');
            $table->index('seen_at', 'ix_iq_pendientes');
            $table->index('seen_by_user_id', 'ix_iq_visto_por');

            $table->foreign('invitation_id', 'fk_iq_invitacion')
                ->references('id')->on('invitations')->restrictOnDelete();
            $table->foreign('seen_by_user_id', 'fk_iq_visto_por')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: 'invitation_questions', nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$nombre]) {
            Restriccion::quitar('invitation_questions', $nombre);
        }

        Schema::dropIfExists('invitation_questions');
    }

    /** @return list<array{0:string,1:string,2:list<string>,3:string}> */
    private static function restricciones(): array
    {
        return [
            // Una pregunta vacia no es una pregunta: es un boton pulsado sin
            // querer, y ocupa un hueco en la lista de cosas por atender.
            ['ck_iq_body', 'CHAR_LENGTH(TRIM(body)) >= 3', ['body'],
                'Escribe la pregunta.'],
            // Las dos columnas del «visto» van juntas o no van. Un `seen_at` sin
            // nombre no responde «.quien se hizo cargo?», que es para lo unico
            // que sirve marcar algo como visto.
            ['ck_iq_seen', '(seen_at IS NULL AND seen_by_user_id IS NULL)'
                .' OR (seen_at IS NOT NULL AND seen_by_user_id IS NOT NULL)',
                ['seen_at', 'seen_by_user_id'],
                'Marcar una pregunta como vista exige decir quien se hizo cargo.'],
        ];
    }
};
