<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos con nombre, nunca comprobaciones de rol repartidas por el código.
 *
 * docs/08 lo lista como regla no negociable: el código pregunta por el permiso
 * ('campaign.view_margin'), no por el rol. Los roles se reconfiguran; los
 * permisos que protegen el margen interno (BR-FIN-007) no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50);
            $table->string('name', 80);
            // Un rol pertenece a un ámbito: no tiene sentido dar un rol interno
            // a un creador, y el ámbito lo impide antes de que ocurra.
            $table->string('scope', 20)->default('internal');
            $table->boolean('is_system')->default(false);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_roles_code');
        });

        Restriccion::comprobacion(
            tabla: 'roles',
            nombre: 'ck_roles_scope',
            expresion: "scope IN ('internal','client','creator')",
            columnas: ['scope'],
            mensaje: 'Ambito de rol no valido.',
        );

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80);
            $table->string('module', 40);
            $table->string('description', 160);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_permissions_code');
            $table->index('module', 'ix_permissions_module');
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id', 'ix_permission_role_permission');

            $table->foreign('role_id', 'fk_pr_role')
                ->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id', 'fk_pr_permission')
                ->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            // Quién concedió el rol y cuándo: es la pregunta que hace un auditor.
            $table->dateTime('assigned_at', 3);
            $table->unsignedBigInteger('assigned_by')->nullable();

            $table->primary(['user_id', 'role_id']);
            $table->index('role_id', 'ix_role_user_role');

            $table->foreign('user_id', 'fk_ru_user')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id', 'fk_ru_role')
                ->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('assigned_by', 'fk_ru_assigner')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
