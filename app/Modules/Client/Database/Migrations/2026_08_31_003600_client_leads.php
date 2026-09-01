<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El prospecto que llega por la portada (9.21c).
 *
 * ### Por qué una tabla y no un correo
 *
 * Es la decisión del negocio, y el motivo es el que hace la diferencia: **hoy el
 * correo está en «log»** —no sale de este servidor— y una instalación con el
 * SMTP mal configurado perdería cada contacto **sin que nadie se entere**. Una
 * fila no se pierde, se puede contar, y contesta «¿cuántos llegaron el mes
 * pasado y qué pasó con ellos?». El aviso por correo va encima, no en lugar de.
 *
 * ### El mismo diseño que `creator_applications`, a propósito
 *
 * La postulación de un creador y el contacto de una marca son el mismo problema
 * —alguien de fuera deja sus datos y alguien de dentro los atiende— así que se
 * resuelven igual: estado, quién lo revisó y cuándo, y una **columna puerta** que
 * impide dos contactos abiertos del mismo correo. Copiar la forma que ya funciona
 * es más barato que inventar la segunda, y quien conozca una bandeja conoce las
 * dos.
 *
 * ### Los estados, y por qué `discarded` no es `deleted`
 *
 * `new` → `contacted` → `qualified` | `discarded` | `converted`.
 *
 * Descartar exige **un motivo escrito**, y no borra nada. De esta tabla sale la
 * respuesta a «¿de dónde salió este cliente?» y a «¿cuántos descartamos y por
 * qué?» —que es lo único que permite darse cuenta de que se está descartando
 * mal—. Borrarlo se lleva por delante las dos preguntas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_leads', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('company_name', 160);
            $table->string('contact_name', 160);
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            $table->foreignId('country_id');
            $table->string('website', 255)->nullable();
            // Lo que escribe quien contacta, con sus palabras. Sin desplegables
            // de presupuesto: un rango de presupuesto es un catalogo, y meterlo
            // en el codigo seria `DEC-190` roto en la primera pantalla que ve un
            // cliente. Cuando haga falta, sera una tabla.
            $table->string('message', 1000)->nullable();
            $table->string('source', 20)->default('landing');
            $table->string('status', 20)->default('new');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->dateTime('reviewed_at', 3)->nullable();
            // Por que se descarto, o lo que haga falta recordar del contacto.
            $table->string('note', 500)->nullable();
            // Cuando se convierte en cliente de verdad. La conversion en si
            // --crear la organizacion con su perfil fiscal-- ya existe y no se
            // toca aqui: esta columna es el puente, y se rellena a mano hasta
            // que alguien construya el atajo.
            $table->unsignedBigInteger('client_organization_id')->nullable();
            $table->dateTime('submitted_at', 3);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_clead_uuid');
            $table->index(['status', 'submitted_at'], 'ix_clead_estado');
            $table->index('country_id', 'ix_clead_pais');

            $table->foreign('country_id', 'fk_clead_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('reviewed_by_user_id', 'fk_clead_revisor')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('client_organization_id', 'fk_clead_cliente')
                ->references('id')->on('client_organizations')->restrictOnDelete();
        });

        // Columna puerta: un solo contacto ABIERTO por correo.
        //
        // Sin ella, quien rellena el formulario tres veces porque nadie le
        // contesta aparece tres veces en la bandeja, y el equipo cree que son
        // tres marcas. Lo cerrado --calificado, descartado o convertido-- no
        // ocupa el hueco: se puede volver a contactar el ano que viene, y esa
        // segunda vez es un contacto nuevo de verdad.
        DB::statement(
            'ALTER TABLE `client_leads` ADD COLUMN `lead_open_key` VARCHAR(255) '
            ."GENERATED ALWAYS AS (CASE WHEN `status` IN ('new','contacted') "
            .'THEN LOWER(`email`) ELSE NULL END) STORED',
        );
        DB::statement('ALTER TABLE `client_leads` ADD UNIQUE KEY `uq_clead_abierto` (`lead_open_key`)');

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // De aqui sale «.de donde salio este cliente?» y «.cuantos descartamos y
        // por que?». Descartar es la forma de decir que no; borrar se lleva por
        // delante las dos preguntas.
        DB::statement('DROP TRIGGER IF EXISTS `tg_clead_no_delete`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_clead_no_delete`
            BEFORE DELETE ON `client_leads`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Un contacto no se borra: descartelo con su motivo.';
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_clead_no_delete`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('client_leads');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['client_leads', 'ck_clead_status',
                "status IN ('new','contacted','qualified','discarded','converted')",
                ['status'], 'Estado de contacto no valido.'],
            // `note IS NOT NULL` ANTES del largo, por la leccion de `9.12`:
            // `CHAR_LENGTH(NULL)` es NULL, la conjuncion entera es NULL y un
            // CHECK solo rechaza cuando es FALSO. Sin esa mitad, descartar SIN
            // NINGUN motivo pasaria --justo lo que la regla existe para impedir--.
            ['client_leads', 'ck_clead_descartado',
                "status <> 'discarded' OR (note IS NOT NULL AND CHAR_LENGTH(TRIM(note)) >= 10)",
                ['status', 'note'], 'Descartar un contacto exige decir por que.'],
            ['client_leads', 'ck_clead_convertido',
                "status <> 'converted' OR client_organization_id IS NOT NULL",
                ['status', 'client_organization_id'],
                'Un contacto convertido dice en que cliente se convirtio.'],
            // Salir de `new` es una decision de alguien: queda quien y cuando.
            ['client_leads', 'ck_clead_revisado',
                "status = 'new' OR (reviewed_at IS NOT NULL AND reviewed_by_user_id IS NOT NULL)",
                ['status', 'reviewed_at', 'reviewed_by_user_id'],
                'Mover un contacto deja quien lo movio y cuando.'],
            ['client_leads', 'ck_clead_correo', "email LIKE '%_@_%'",
                ['email'], 'El correo no tiene forma de correo.'],
            ['client_leads', 'ck_clead_web',
                "website IS NULL OR website LIKE 'http://%' OR website LIKE 'https://%'",
                ['website'], 'La web va con http o https.'],
        ];
    }
};
