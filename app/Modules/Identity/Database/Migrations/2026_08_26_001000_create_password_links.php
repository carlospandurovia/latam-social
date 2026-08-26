<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces de contraseña: alta inicial y recuperación (`5.9` + `4.1`).
 *
 * ### Por qué una tabla propia y no `password_reset_tokens`
 *
 * La tabla del esqueleto de Laravel tiene tres columnas —`email`, `token`,
 * `created_at`— y **no sirve para lo que aquí hace falta**:
 *
 * | Lo que falta | Por qué importa |
 * |---|---|
 * | El **propósito** | un enlace de alta dura 72 h y uno de recuperación 1 h (`DEC-113`) |
 * | `used_at` | de un solo uso: usarlo lo quema |
 * | `expires_at` explícito | la caducidad es del enlace, no una constante global |
 * | `user_id` | el correo puede cambiar; la cuenta no |
 * | Quién y desde dónde | un enlace de contraseña es evidencia de seguridad |
 *
 * ### El token no se guarda: se guarda su huella
 *
 * `token_sha256`, nunca el token. Es la misma decisión que `email_log` (la
 * huella, no el cuerpo) y que los medios de pago (la máscara, no el número), y
 * aquí es la más importante de las tres: **quien lea esta tabla no puede
 * entrar en ninguna cuenta.** Un volcado de base de datos filtrado sería, si no,
 * una llave maestra de todo el sistema.
 *
 * El token viaja **una sola vez**, dentro del correo. Si se pierde, se pide
 * otro; no hay forma de recuperarlo desde aquí, y eso es lo correcto.
 *
 * ### `used_at` y `revoked_at` son dos columnas y no una
 *
 * Un enlace muere de tres maneras: lo usan, caduca, o llega otro que lo
 * sustituye. La primera versión de esta tabla marcaba el reemplazo escribiendo
 * `used_at` —era una columna menos— y eso **habría metido una mentira en la
 * evidencia**: el día que alguien pregunte *«¿usó el enlace que le mandamos?»*,
 * la respuesta tiene que salir de una columna que sólo se escribe cuando alguien
 * lo usó de verdad. Además chocaba con `ck_pl_used`, que exige la IP de quien lo
 * usa — y a un enlace que sustituyes no le puedes poner una IP.
 *
 * Caducar no se escribe: es `expires_at` comparado con el reloj. Un estado que
 * se deduce no se guarda, porque guardarlo obliga a un proceso que lo mantenga
 * al día y a explicar qué pasa cuando no ha corrido.
 *
 * ### Un enlace nuevo invalida el anterior
 *
 * Decisión de negocio (2026-08-26). Si alguien pide recuperación porque sospecha
 * que le entraron al buzón, un enlace viejo que sigue funcionando es exactamente
 * el agujero que quería cerrar.
 *
 * Lo garantiza `uq_pl_vigente` con **columna puerta**: vale 1 sólo mientras el
 * enlace no se ha usado ni se ha revocado, y el índice único deja **uno por
 * (usuario, propósito)**. Es el mismo mecanismo de las doce puertas del esquema.
 * Revocar no es borrar: un enlace que existió es evidencia y se queda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_links', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->unsignedBigInteger('user_id');
            // `initial` (alta) o `reset` (recuperacion). No es cosmetico: cada
            // uno tiene su caducidad y su texto, y mezclarlos haria que la
            // ventana larga del alta valiera tambien para recuperar.
            $table->string('purpose', 20);
            $table->char('token_sha256', 64);
            $table->dateTime('expires_at', 3);
            $table->dateTime('used_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 40)->nullable();
            // Un enlace de contrasena es evidencia de seguridad: se guarda quien
            // lo pidio (o `null` si lo pidio el propio interesado sin sesion) y
            // desde donde se uso.
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->binary('used_ip', 16)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_pl_uuid');
            // El indice unico del token no es solo higiene: es lo que hace que
            // dos peticiones simultaneas no puedan acabar en dos filas con la
            // misma huella.
            $table->unique('token_sha256', 'uq_pl_token');
            $table->index(['user_id', 'purpose'], 'ix_pl_usuario');
            $table->index('expires_at', 'ix_pl_caducidad');
            $table->index('requested_by_user_id', 'ix_pl_solicitante');

            $table->foreign('user_id', 'fk_pl_usuario')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'fk_pl_solicitante')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE password_links ADD COLUMN vigente_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN used_at IS NULL AND revoked_at IS NULL '
            .'THEN 1 ELSE NULL END) STORED',
        );

        DB::statement(
            'ALTER TABLE password_links ADD UNIQUE KEY uq_pl_vigente '
            .'(vigente_gate, user_id, purpose)',
        );

        foreach (self::restricciones() as [$nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: 'password_links', nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$nombre]) {
            Restriccion::quitar('password_links', $nombre);
        }

        Schema::dropIfExists('password_links');
    }

    /** @return list<array{0:string,1:string,2:list<string>,3:string}> */
    private static function restricciones(): array
    {
        return [
            ['ck_pl_purpose', "purpose IN ('initial', 'reset')", ['purpose'],
                'Proposito de enlace no valido: solo `initial` (alta) o `reset` (recuperacion).'],
            // Usado exige DESDE DONDE. Un enlace de contrasena que se consumio
            // sin dejar rastro de origen no sirve para investigar nada, y es
            // justo la fila que se mira cuando alguien dice «yo no entre».
            ['ck_pl_used', 'used_at IS NULL OR used_ip IS NOT NULL', ['used_at', 'used_ip'],
                'Un enlace usado tiene que registrar desde donde se uso.'],
            // Revocar exige MOTIVO, por lo mismo que un rechazo de solicitud lo
            // exige: «se revoco» sin decir por que no explica nada seis meses
            // despues.
            ['ck_pl_revoked', 'revoked_at IS NULL OR revoked_reason IS NOT NULL',
                ['revoked_at', 'revoked_reason'],
                'Un enlace revocado tiene que decir por que se revoco.'],
            // Y las dos muertes se excluyen: un enlace usado no se puede revocar
            // despues, ni uno revocado usarse. Si las dos columnas pudieran
            // convivir, la puerta `vigente_gate` seguiria funcionando y la
            // evidencia diria dos cosas a la vez.
            ['ck_pl_terminal', 'used_at IS NULL OR revoked_at IS NULL', ['used_at', 'revoked_at'],
                'Un enlace no puede estar usado y revocado a la vez.'],
        ];
    }
};
