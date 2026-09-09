<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo una campaña está «retrasada» y cuándo «en riesgo» (`D-6`).
 *
 * ### Por qué esto es una tabla y no tres constantes
 *
 * Porque son un juicio del negocio, no una propiedad del programa. «Siete días
 * antes del cierre» y «por debajo del 60 % de avance» son la clase de número
 * que se ajusta con datos reales el segundo mes, y con los umbrales en el
 * código ajustarlos sería una migración y un despliegue.
 *
 * Es `DEC-190` literal: *«el sistema debe ser 100 % parametrizable desde el
 * admin»*. El código pone la REGLA —qué se compara con qué— y la configuración
 * pone el VALOR.
 *
 * ### Y por qué no bloquea nunca
 *
 * La fila se siembra aquí con los valores de partida que el negocio aprobó, así
 * que el semáforo funciona desde el primer minuto. Si la fila no estuviera
 * —una instalación a medias, alguien que la borró— `Semaforo::umbrales()`
 * devuelve esos mismos valores y la pantalla de configuración lo dice en ámbar.
 * Un panel que se apaga porque falta una fila de configuración es exactamente
 * lo que `DEC-190` prohíbe.
 *
 * ### Una sola fila, impuesta por la base
 *
 * `singleton` vale 1 siempre y es única. No es un adorno: sin eso, dos filas
 * son dos verdades y el panel enseña la que devuelva el motor primero, que no
 * es una decisión de nadie. Es la misma técnica de la columna puerta que usa
 * `campaign_requirements`, con la puerta abierta a un solo lado.
 *
 * ### Lo que estos tres números NO saben
 *
 * Son globales. Una campaña de tres días y una de seis meses comparten los
 * «siete días antes del cierre», y para la primera ese umbral cubre la campaña
 * entera. Es una simplificación consciente y queda anotada como `T-105`: el día
 * que estorbe, el sitio donde se arregla es una fila por campaña que herede de
 * ésta, no un `if` en la consulta.
 */
return new class extends Migration
{
    /** Los valores de partida que aprobó el negocio (`DEC-319`). */
    private const PARTIDA = [
        'risk_days_before_end' => 7,
        'min_progress_pct' => 60,
        'recruiting_days_before_start' => 5,
    ];

    public function up(): void
    {
        Schema::create('tracking_thresholds', function (Blueprint $table): void {
            $table->id();

            // La puerta: vale 1 y solo puede haber una. Ver el bloque de arriba.
            $table->unsignedTinyInteger('singleton')->default(1);

            // `N` -- a cuantos dias del cierre se empieza a mirar el avance.
            // En dias naturales y no habiles: un fin de semana no detiene una
            // fecha de publicacion, y contar habiles obligaria a tener el
            // calendario de feriados de cada pais para responder «faltan 3».
            $table->unsignedSmallInteger('risk_days_before_end')->default(7);

            // `P` -- por debajo de que porcentaje de avance eso es riesgo.
            // Entero: el negocio discute «60 o 70», no «62,5».
            $table->unsignedTinyInteger('min_progress_pct')->default(60);

            // `M` -- a cuantos dias del arranque una convocatoria incompleta
            // deja de ser normal y pasa a ser un problema.
            $table->unsignedSmallInteger('recruiting_days_before_start')->default(5);

            // Quien lo toco por ultima vez. La bitacora guarda el detalle; esto
            // es para poder preguntarle a alguien sin abrirla.
            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('singleton', 'uq_tt_unica');
            $table->index('updated_by_user_id', 'ix_tt_usuario');

            $table->foreign('updated_by_user_id', 'fk_tt_usuario')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Sembrada, no vacia. Un semaforo sin umbrales no es un semaforo
        // apagado: es una pantalla que no se puede leer.
        DB::table('tracking_thresholds')->insert(self::PARTIDA + [
            'singleton' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('tracking_thresholds');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['tracking_thresholds', 'ck_tt_unica', 'singleton = 1', ['singleton'],
                'Los umbrales del semaforo son una sola fila.'],

            // El techo de un ano no es capricho: un umbral de 4000 dias haria
            // que TODAS las campanas salieran en ambar para siempre, y nadie
            // sabria por que. Un rango que se ve es un rango que se discute.
            ['tracking_thresholds', 'ck_tt_cierre', 'risk_days_before_end BETWEEN 0 AND 365',
                ['risk_days_before_end'],
                'Los dias antes del cierre van entre 0 y 365.'],

            ['tracking_thresholds', 'ck_tt_avance', 'min_progress_pct BETWEEN 0 AND 100',
                ['min_progress_pct'],
                'El avance minimo es un porcentaje entre 0 y 100.'],

            ['tracking_thresholds', 'ck_tt_arranque', 'recruiting_days_before_start BETWEEN 0 AND 365',
                ['recruiting_days_before_start'],
                'Los dias antes del arranque van entre 0 y 365.'],
        ];
    }
};
