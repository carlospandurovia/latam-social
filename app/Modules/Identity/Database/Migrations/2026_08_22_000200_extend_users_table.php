<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende la tabla users de Laravel en lugar de sustituirla.
 *
 * Decisión consciente: pelearse con la migración base del framework para ganar
 * tres columnas no compensa. Lo que necesitamos se añade encima y `php artisan
 * migrate` sigue funcionando de fábrica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // uuid solo donde hace falta exponer la entidad hacia fuera (docs 2.3 §6).
            $table->char('uuid', 36)->nullable()->after('id');
            $table->string('user_type', 20)->default('internal')->after('name');
            $table->string('status', 20)->default('active')->after('user_type');
            $table->string('locale', 10)->default('es')->after('status');
            $table->string('timezone', 64)->default('America/Lima')->after('locale');
            $table->boolean('must_change_password')->default(false);
            $table->dateTime('last_login_at', 3)->nullable();
            $table->dateTime('deactivated_at', 3)->nullable();
        });

        // VARCHAR + restricción, nunca el tipo ENUM de MySQL (docs 2.3 §7): añadir
        // un valor a un ENUM reescribe la tabla entera; esto es metadatos.
        //
        // Y no se escribe el CHECK a mano: Restriccion decide si el motor lo
        // aplica de verdad o si hace falta un TRIGGER equivalente (DEC-042).
        Restriccion::comprobacion(
            tabla: 'users',
            nombre: 'ck_users_type',
            expresion: "user_type IN ('internal','client','creator')",
            columnas: ['user_type'],
            mensaje: 'Tipo de usuario no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'users',
            nombre: 'ck_users_status',
            expresion: "status IN ('active','suspended','deactivated')",
            columnas: ['status'],
            mensaje: 'Estado de usuario no valido.',
        );

        // Un usuario nunca se borra, se desactiva (docs 2.2 §5). Pero entonces su
        // email debe poder reutilizarse, y el índice único de Laravel lo impide.
        //
        // MySQL y MariaDB no tienen índices parciales con WHERE. El equivalente es
        // una columna generada que vale NULL cuando la fila no cuenta: NULL no
        // colisiona en un índice único. Además normaliza a minúsculas, así que
        // "ANA@" y "ana@" tampoco pueden coexistir.
        DB::statement('ALTER TABLE users DROP INDEX users_email_unique');
        DB::statement(
            'ALTER TABLE users ADD COLUMN email_active_key VARCHAR(255) '
            ."GENERATED ALWAYS AS (CASE WHEN status <> 'deactivated' THEN LOWER(email) ELSE NULL END) STORED"
        );
        DB::statement('ALTER TABLE users ADD UNIQUE KEY uq_users_email_active (email_active_key)');
        DB::statement('ALTER TABLE users ADD UNIQUE KEY uq_users_uuid (uuid)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP INDEX uq_users_uuid');
        DB::statement('ALTER TABLE users DROP INDEX uq_users_email_active');
        DB::statement('ALTER TABLE users DROP COLUMN email_active_key');
        DB::statement('ALTER TABLE users ADD UNIQUE KEY users_email_unique (email)');
        Restriccion::quitar('users', 'ck_users_status');
        Restriccion::quitar('users', 'ck_users_type');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'uuid', 'user_type', 'status', 'locale', 'timezone',
                'must_change_password', 'last_login_at', 'deactivated_at',
            ]);
        });
    }
};
