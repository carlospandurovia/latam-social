<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién publica el tipo de cambio, y de qué lado (iteración 9.1).
 *
 * `exchange_rates` existe desde la Fase 2 y **nadie la ha llenado nunca**. Antes
 * de que alguien lo haga hay que arreglar tres cosas que sólo se ven cuando la
 * tabla tiene datos, y que con datos ya no se pueden arreglar barato.
 *
 * ### 1. La tabla admitía un empate, y un empate aquí es dinero mal convertido
 *
 * `uq_exchange_rates` incluye `source` **a propósito** —dos fuentes pueden
 * discrepar el mismo día y hay que poder decir de cuál salió la que se aplicó—,
 * pero de ahí no salía **cuál se aplica**. Es literalmente el `EMPATE` de
 * `CoberturaFacturacion`, que una vez emitió una factura desde la sociedad
 * equivocada: la respuesta entonces fue `uq_lec_country` + `lec_sin_solape`, y
 * la respuesta aquí es la misma. `fx_official_sources` dice **qué fuente manda
 * para un par de monedas y desde cuándo**, con periodos, para que el histórico
 * se siga explicando con la fuente de entonces (`BR-FIN-009`).
 *
 * ### 2. SUNAT publica DOS tasas el mismo día, y no son intercambiables
 *
 * Compra y venta. Con una sola columna `rate` sólo cabe una, y elegir cuál
 * guardar aquí sería tomar por mi cuenta una decisión contable. `side` las
 * separa y entra en la clave: la misma fuente publica las dos el mismo día sin
 * pisarse, y **cuál aplica a cada operación es una decisión declarada**, no un
 * efecto de qué fila se guardó. Queda `Q-63` para el contador.
 *
 * ### 3. Una tasa publicada se podía reescribir
 *
 * `tg_fx_no_delete` existe desde `3.12` — la fila no se puede borrar. Pero un
 * `UPDATE` la reescribía entera, y `BR-FIN-009` dice que **los históricos no se
 * recalculan**. Un asiento guarda su `exchange_rate_snapshot`, así que
 * reescribir la tasa no cambia lo ya convertido; lo que rompe es la capacidad de
 * explicarlo — el asiento diría 3,742 y su fuente diría 3,751. Se bloquea el
 * `UPDATE` **entero**, como `tg_cvw_inmutable`, porque no hay ninguna columna de
 * esta tabla que tenga sentido cambiar después de publicada.
 *
 * Qué pasa si la fuente **corrige** una tasa ya publicada queda como `Q-62`: no
 * lo invento hoy, y hoy no estorba porque la tabla está vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El catalogo de fuentes. Hasta hoy `exchange_rates.source` era texto
        // libre de 40 caracteres, o sea que una tasa podia decir que la publico
        // 'bcrp' sin que nadie hubiera dicho nunca quien es 'bcrp' --y de la
        // comparacion de ese texto con `fx_official_sources.source_code` depende
        // que tasa se aplica--.
        //
        // Lo que la clave ajena NO arregla, comprobado contra el motor: el
        // cotejamiento es `utf8mb4_unicode_ci`, asi que 'SUNAT' y 'sunat' son el
        // MISMO valor para ella y las dos entran. Si algun dia hace falta que
        // no, eso es un `COLLATE ..._bin`, no una clave ajena. Se dice porque la
        // primera version de este comentario afirmaba lo contrario.
        Schema::create('fx_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_fxs_code');
        });

        // `side` entra en la clave: la misma fuente publica compra y venta el
        // mismo dia. El valor por defecto es 'mid' para que las filas que ya
        // existieran --si las hubiera-- signifiquen algo y no queden en blanco.
        // `VARCHAR(10)` y no `VARCHAR(4)`, que es lo que pedirian los tres
        // valores. Con 4, meter 'medio' lo rechaza el ANCHO DE LA COLUMNA --un
        // 1406-- y `ck_fx_side` no llega a opinar: la regla estaria puesta y no
        // seria ella la que contesta. Es la leccion de `T-48` aplicada al reves,
        // y sale en la primera pasada de la suite.
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->string('side', 10)->default('mid')->after('rate');
        });

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('uq_exchange_rates');
        });

        DB::statement(
            'ALTER TABLE `exchange_rates` ADD UNIQUE KEY `uq_fx_rate` '
            .'(`base_currency_code`, `quote_currency_code`, `rate_date`, `source`, `side`)',
        );

        // La fuente deja de ser texto libre. No hace falta sembrar nada antes:
        // una clave ajena sobre una tabla VACIA no exige que la referida tenga
        // filas. Las dos fuentes de arranque --`sunat` y `manual`-- viven donde
        // vive el resto del catalogo: `CimientosSeeder` y `tools/pruebas/semilla.sql`.
        // Ninguna migracion de este proyecto siembra datos, y la primera que lo
        // intento --esta-- rompio `recolectar-esquema.php`, que ejecuta los
        // `up()` sin Laravel entero y no tiene `now()`.
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->foreign('source', 'fk_fx_source')
                ->references('code')->on('fx_sources')->restrictOnDelete();
        });

        // Quien manda para cada par, y desde cuando.
        Schema::create('fx_official_sources', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency_code', 3);
            $table->char('quote_currency_code', 3);
            $table->string('source_code', 40);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['base_currency_code', 'quote_currency_code'], 'ix_fos_pair');
            $table->index('source_code', 'ix_fos_source');

            $table->foreign('base_currency_code', 'fk_fos_base')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('quote_currency_code', 'fk_fos_quote')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('source_code', 'fk_fos_source')
                ->references('code')->on('fx_sources')->restrictOnDelete();
        });

        // La columna puerta: una sola fuente VIGENTE por par. Misma forma que
        // `uq_lec_country`, y por el mismo motivo.
        DB::statement(
            'ALTER TABLE `fx_official_sources` ADD COLUMN `current_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `valid_to` IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `fx_official_sources` ADD UNIQUE KEY `uq_fos_current` '
            .'(`current_gate`, `base_currency_code`, `quote_currency_code`)',
        );

        // Y la otra mitad, que es la que se olvida: `uq_fos_current` garantiza
        // una VIGENTE, no una por FECHA. Convertir un importe del 3 de marzo
        // resuelve por par Y por fecha, asi que dos periodos cerrados que se
        // pisen son el mismo empate, sólo que para una fecha pasada.
        Periodo::sinSolape(
            tabla: 'fx_official_sources',
            nombre: 'fos_sin_solape',
            serie: ['base_currency_code', 'quote_currency_code'],
            mensaje: 'Ya hay una fuente oficial para ese par en esas fechas: cierre la anterior el dia antes.',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Una tasa publicada no se reescribe (`BR-FIN-009`).
        DB::statement('DROP TRIGGER IF EXISTS `tg_fx_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_fx_inmutable`
            BEFORE UPDATE ON `exchange_rates`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Un tipo de cambio publicado no se modifica: los historicos no se recalculan.';
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_fx_inmutable`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Periodo::quitar('fx_official_sources', 'fos_sin_solape');
        Schema::dropIfExists('fx_official_sources');

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropForeign('fk_fx_source');
            $table->dropUnique('uq_fx_rate');
            $table->dropColumn('side');
        });

        DB::statement(
            'ALTER TABLE `exchange_rates` ADD UNIQUE KEY `uq_exchange_rates` '
            .'(`base_currency_code`, `quote_currency_code`, `rate_date`, `source`)',
        );

        Schema::dropIfExists('fx_sources');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['exchange_rates', 'ck_fx_side', "side IN ('buy','sell','mid')", ['side'],
                'Lado del tipo de cambio no valido: compra, venta o medio.'],
            ['fx_official_sources', 'ck_fos_distinct', 'base_currency_code <> quote_currency_code',
                ['base_currency_code', 'quote_currency_code'],
                'Una fuente oficial necesita dos monedas distintas.'],
            ['fx_official_sources', 'ck_fos_dates', 'valid_to IS NULL OR valid_to >= valid_from',
                ['valid_from', 'valid_to'],
                'Una fuente oficial no puede terminar antes de empezar.'],
        ];
    }
};
