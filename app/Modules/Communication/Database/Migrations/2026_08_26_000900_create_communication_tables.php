<?php

declare(strict_types=1);

namespace App\Modules\Communication\Database\Migrations;

use App\Shared\Database\Periodo;
use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El correo: plantillas versionadas y registro de envíos (4.9).
 *
 * ### Por qué el correo bloqueaba media Fase 7
 *
 * `F4.9` no es una pieza de infraestructura más. Sin ella no se puede:
 *
 * | Qué | Dónde estaba parado |
 * |---|---|
 * | Invitar a un creador a una campaña | `7.6` — «enviar, expirar, aceptar» empieza por enviar |
 * | Mandarle su enlace de contraseña al aprobarlo | `5.9`, hoy por comando y a mano |
 * | Recuperar la contraseña | `4.1`, sin camino ninguno |
 * | Avisarle cuando cambian sus datos fiscales | `T-10` y `BR-CREATOR-007`, que es 🔴 |
 *
 * ### `email_templates`: el mismo patrón que `terms_versions`
 *
 * Versión + vigencia, y **la vigente es la que no tiene `effective_to`**. Se
 * reutiliza a propósito: es la misma pregunta —*«¿qué texto estaba vigente el
 * día que se envió?»*— y ya tiene una respuesta probada en este proyecto.
 *
 * Lo que se envió tiene que poder demostrarse **años después**, y por eso una
 * versión publicada no se edita: se publica la siguiente y la anterior se cierra
 * el día antes (`Vigencia::cerrarElDiaAntesDe`). `content_sha256` lo hace
 * comprobable: si alguien edita el texto de una versión ya usada, la huella deja
 * de cuadrar con la del registro de envío.
 *
 * La columna puerta `current_gate` + `uq_et_vigente` garantiza **una sola
 * vigente por (código, idioma)**. Es el mismo mecanismo de las once puertas que
 * ya hay en el esquema.
 *
 * ### `email_log`: qué se guarda, y sobre todo qué NO
 *
 * Decisión de negocio (2026-08-26): se guarda **plantilla, versión, idioma,
 * asunto y la huella del cuerpo**. El cuerpo renderizado **no**.
 *
 * Es la regla del proyecto —*«no guardar información sensible innecesariamente
 * en logs»*— aplicada con cabeza. El cuerpo lleva el nombre de la persona, a
 * veces importes y a veces datos fiscales; guardarlo convierte esta tabla en una
 * segunda copia de la ficha del creador, que hay que proteger, anonimizar y
 * borrar igual que la primera.
 *
 * Y no se pierde nada: la versión de la plantilla es **inmutable** y la huella
 * demuestra que el cuerpo enviado era exactamente el que sale de renderizarla.
 * *«Me llegaron condiciones distintas»* se contesta con la versión y la huella,
 * sin tener el texto guardado dos veces.
 *
 * ### Y no se borra
 *
 * Un envío es evidencia (`BR-CREATOR-007` obliga a **notificar**, y notificar
 * hay que poder demostrarlo). Se aplica el mismo criterio de `3.12`: no hay
 * borrado, y un envío equivocado se marca, no se quita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            // `code` es el nombre estable del aviso: `creator.tax_profile_changed`.
            // Lo usa el codigo; el idioma y la version los resuelve el servicio.
            $table->string('code', 60);
            $table->string('locale', 10);
            $table->string('version', 20);
            $table->string('subject', 200);
            $table->longText('body');
            // Las variables que la plantilla espera, para poder avisar ANTES de
            // enviar de que falta una. Sin esto, un `{{ nombre }}` sin valor sale
            // literal en el correo de una persona.
            $table->json('variables')->nullable();
            $table->char('content_sha256', 64);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_et_uuid');
            $table->unique(['code', 'locale', 'version'], 'uq_et_version');
            $table->index(['code', 'locale', 'effective_from'], 'ix_et_vigencia');
            $table->index('created_by_user_id', 'ix_et_autor');

            $table->foreign('created_by_user_id', 'fk_et_autor')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // La puerta: una sola version vigente por (codigo, idioma). Es el mismo
        // mecanismo que `uq_ctxp_current` o `uq_cpm_open_account`: NULL no
        // colisiona con NULL, asi que la columna generada vale 1 solo cuando la
        // fila es la vigente y el indice unico solo la mira a ella.
        DB::statement(
            'ALTER TABLE email_templates ADD COLUMN current_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE email_templates ADD UNIQUE KEY uq_et_vigente (current_gate, code, locale)',
        );

        Schema::create('email_log', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->unsignedBigInteger('email_template_id')->nullable();
            // Se COPIAN, no se leen por la foranea. `BR-LE-001` aplicado al
            // correo: dentro de dos anos, «que plantilla se le envio» tiene que
            // responderlo esta fila, no una consulta a la plantilla de entonces
            // --que puede haber cambiado de version y sonar igual de convincente.
            $table->string('template_code', 60);
            $table->string('template_version', 20);
            $table->string('template_locale', 10);
            // El idioma que se PIDIO. Distinto del anterior cuando hubo caida:
            // de aqui sale la lista de plantillas que faltan por traducir.
            $table->string('locale_requested', 10);
            $table->string('to_email', 255);
            $table->string('subject', 200);
            // La huella del cuerpo renderizado. El cuerpo NO se guarda: lleva
            // datos de la persona y la version de la plantilla ya es inmutable.
            $table->char('body_sha256', 64);
            $table->string('status', 15)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            // A que se refiere el aviso, para poder verlos juntos en la ficha.
            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->dateTime('queued_at', 3);
            $table->dateTime('sent_at', 3)->nullable();
            $table->dateTime('failed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_el_uuid');
            $table->index(['status', 'queued_at'], 'ix_el_estado');
            $table->index(['to_email', 'queued_at'], 'ix_el_destinatario');
            $table->index(['related_type', 'related_id'], 'ix_el_relacionado');
            $table->index('email_template_id', 'ix_el_plantilla');

            $table->foreign('email_template_id', 'fk_el_plantilla')
                ->references('id')->on('email_templates')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Dos versiones de la misma plantilla no pueden estar vigentes el mismo
        // dia. Lo compila `Periodo`, igual que las ocho reglas de periodo que ya
        // hay: escribirlo a mano es donde aparecio once veces el error de un dia.
        Periodo::sinSolape(
            tabla: 'email_templates',
            nombre: 'et_sin_solape',
            serie: ['code', 'locale'],
            mensaje: 'Ya hay una version de esa plantilla vigente en esas fechas: cierre la anterior el dia antes.',
            desde: 'effective_from',
            hasta: 'effective_to',
        );
    }

    public function down(): void
    {
        Periodo::quitar('email_templates', 'et_sin_solape');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('email_log');
        Schema::dropIfExists('email_templates');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['email_templates', 'ck_et_dates', 'effective_to IS NULL OR effective_to >= effective_from',
                ['effective_to', 'effective_from'], 'Una plantilla no puede dejar de estar vigente antes de empezar.'],
            ['email_log', 'ck_el_status', "status IN ('queued','sent','failed','cancelled')",
                ['status'], 'Estado de envio no valido.'],
            // Enviado exige la fecha, y fallido exige el motivo. Un `failed` sin
            // error obliga a mirar el log del servidor para saber que paso, que
            // es exactamente lo que esta tabla existe para evitar.
            ['email_log', 'ck_el_sent', "status <> 'sent' OR sent_at IS NOT NULL",
                ['status', 'sent_at'], 'Un correo enviado exige la fecha de envio.'],
            ['email_log', 'ck_el_failed', "status <> 'failed' OR (failed_at IS NOT NULL AND last_error IS NOT NULL)",
                ['status', 'failed_at', 'last_error'], 'Un correo fallido exige cuando fallo Y por que.'],
        ];
    }
};
