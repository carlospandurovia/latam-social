<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La credencial de la fuente, y el registro de lo que trajo el cron (9.2).
 *
 * ### Dónde vive la clave, y por qué no sólo en la pantalla
 *
 * La petición era configurarla desde administración. Se puede, pero la regla de
 * seguridad del proyecto dice *«no almacenar secretos en texto plano en BD
 * cuando exista alternativa segura»*, así que:
 *
 * 1. Si hay `DECOLECTA_API_KEY` en el entorno, **manda el entorno**. Ese es el
 *    sitio bueno: no viaja por el navegador, no está en ninguna tabla y no sale
 *    en un volcado de base de datos.
 * 2. Si no lo hay, se usa `api_key_cipher`, **cifrada** con `Crypt` — la misma
 *    máquina que guarda las cuentas bancarias de los creadores desde `3.8`.
 *
 * `api_key_last4` existe para que la pantalla pueda decir *«termina en 8f2a»*
 * sin descifrar nada. La clave entera no se muestra nunca, ni al que la escribió
 * un minuto antes: una pantalla que la reenseña es una pantalla que la filtra
 * por encima del hombro (`BR-SEC-001`).
 *
 * `credential_set_by_user_id` no es adorno. Una credencial es un permiso de
 * gasto contra un servicio de terceros, y «quién la puso» es la primera
 * pregunta el día que aparezca un consumo raro.
 *
 * ### Y por qué hay una tabla de ejecuciones
 *
 * `Cambio::DIAS_ATRAS` detecta que el cron murió **cuando alguien va a
 * convertir**, o sea el día de la liquidación. `fx_fetch_runs` lo enseña antes:
 * cada intento deja su fecha, su resultado y cuántas tasas nuevas trajo. Un
 * proceso automático que falla en silencio es un proceso que nadie arregla,
 * porque nadie se entera.
 *
 * **No guarda nada de la credencial**, ni siquiera enmascarada, y `detail` es
 * texto nuestro y no el cuerpo de la respuesta: *«no guardar información
 * sensible innecesariamente en logs»* vale también para las tablas que hacen de
 * log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fx_sources', function (Blueprint $table): void {
            $table->string('api_base_url', 255)->nullable()->after('description');
            $table->text('api_key_cipher')->nullable()->after('api_base_url');
            $table->string('api_key_last4', 4)->nullable()->after('api_key_cipher');
            $table->dateTime('credential_set_at', 3)->nullable()->after('api_key_last4');
            $table->unsignedBigInteger('credential_set_by_user_id')->nullable()->after('credential_set_at');

            $table->index('credential_set_by_user_id', 'ix_fxs_credencial');
            $table->foreign('credential_set_by_user_id', 'fk_fxs_credencial')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('fx_fetch_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source_code', 40);
            // Que fecha se PIDIO. Puede ser distinta del dia en que se corrio:
            // el comando admite recuperar dias atrasados.
            $table->date('requested_date');
            $table->dateTime('ran_at', 3);
            $table->string('outcome', 20);
            $table->unsignedSmallInteger('rates_new')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            // Texto NUESTRO, no el cuerpo de la respuesta.
            $table->string('detail', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['source_code', 'ran_at'], 'ix_ffr_source');
            $table->index(['requested_date', 'outcome'], 'ix_ffr_date');

            $table->foreign('source_code', 'fk_ffr_source')
                ->references('code')->on('fx_sources')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Poner una credencial deja rastro completo o no lo deja: media firma
        // --cifrado sin autor, o autor sin fecha-- es peor que ninguna, porque
        // parece que la pregunta «quien la puso» tiene respuesta.
        DB::statement('DROP TRIGGER IF EXISTS `tg_fxs_credencial_firmada`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_fxs_credencial_firmada`
            BEFORE UPDATE ON `fx_sources`
            FOR EACH ROW
            BEGIN
                IF NEW.`api_key_cipher` IS NOT NULL
                   AND (NEW.`credential_set_at` IS NULL
                        OR NEW.`credential_set_by_user_id` IS NULL
                        OR NEW.`api_key_last4` IS NULL) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Una credencial guardada exige quien la puso, cuando, y sus cuatro ultimos.';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_fxs_credencial_firmada`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('fx_fetch_runs');

        Schema::table('fx_sources', function (Blueprint $table): void {
            $table->dropForeign('fk_fxs_credencial');
            $table->dropIndex('ix_fxs_credencial');
            $table->dropColumn([
                'api_base_url', 'api_key_cipher', 'api_key_last4',
                'credential_set_at', 'credential_set_by_user_id',
            ]);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['fx_fetch_runs', 'ck_ffr_outcome',
                "outcome IN ('ok','sin_credencial','sin_fuente','error_http','respuesta_rara','error_red')",
                ['outcome'], 'Resultado de la traida de tipos de cambio no valido.'],
            // Un intento que fallo no pudo traer nada. Si dijera que trajo tres,
            // el registro contaria una historia que no paso.
            ['fx_fetch_runs', 'ck_ffr_nuevas', "outcome = 'ok' OR rates_new = 0",
                ['outcome', 'rates_new'], 'Una traida fallida no pudo anotar ninguna tasa.'],
            ['fx_sources', 'ck_fxs_last4', 'api_key_last4 IS NULL OR CHAR_LENGTH(api_key_last4) = 4',
                ['api_key_last4'], 'La pista de la credencial son exactamente cuatro caracteres.'],
        ];
    }
};
