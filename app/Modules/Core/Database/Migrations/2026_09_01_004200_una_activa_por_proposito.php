<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una integración activa por PROPÓSITO, no por proveedor (9.17f).
 *
 * ### El agujero, encontrado por el negocio
 *
 * > «lo que podríamos relacionar y seleccionar un predeterminado es si el
 * > operador sería de lo mismo, por ejemplo 2 proveedores de FEL, se configuran
 * > pero se activa solo 1»
 *
 * `uq_iconn_active` garantizaba una activa **por proveedor**. Con dos emisores
 * electrónicos dados de alta —el de hoy y el que se está probando— **los dos
 * podían estar activos a la vez**, y nadie sabría cuál emite hasta mirar el
 * código. El ejemplo era hipotético; el hueco era real.
 *
 * Lo que de verdad tiene que ser único es **quién hace este trabajo**: un emisor
 * electrónico por sociedad y entorno, un servidor de correo, una fuente de tipos
 * de cambio. El proveedor es un detalle de con quién se hace.
 *
 * ### Por qué hace falta `purpose_snapshot`
 *
 * Una columna generada sólo puede leer columnas **de su propia fila**, y el
 * propósito vive en `integration_providers`. Así que se copia aquí y lo mantiene
 * el mismo disparador que ya valida la activación: no es una denormalización por
 * comodidad, es lo único que permite que la garantía sea un **índice único** y
 * no un `SELECT COUNT(*)` dentro de un disparador —que no bloquea nada y por
 * tanto no garantiza nada cuando dos personas guardan a la vez—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->string('purpose_snapshot', 30)->nullable()->after('integration_provider_id');
        });

        // Las que ya existan: se copia el proposito de su proveedor.
        DB::statement(
            'UPDATE `integration_connections` ic '
            .'JOIN `integration_providers` ip ON ip.id = ic.integration_provider_id '
            .'SET ic.purpose_snapshot = ip.purpose',
        );

        // La puerta vieja se va entera: columna generada e indice.
        DB::statement('ALTER TABLE `integration_connections` DROP INDEX `uq_iconn_active`');
        DB::statement('ALTER TABLE `integration_connections` DROP COLUMN `active_gate`');

        // Y la nueva, sobre el proposito. Sigue con `COALESCE(legal_entity_id, 0)`
        // porque en un indice unico dos NULL NO colisionan, y sin eso se podrian
        // tener dos integraciones de plataforma activas del mismo proposito.
        DB::statement(
            'ALTER TABLE `integration_connections` ADD COLUMN `active_gate` VARCHAR(70) '
            ."GENERATED ALWAYS AS (CASE WHEN `status` = 'active' "
            ."THEN CONCAT(`purpose_snapshot`, ':', `environment`, ':', "
            .'COALESCE(`legal_entity_id`, 0)) ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `integration_connections` ADD UNIQUE KEY `uq_iconn_activa` (`active_gate`)',
        );

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `integration_connections` DROP INDEX `uq_iconn_activa`');
        DB::statement('ALTER TABLE `integration_connections` DROP COLUMN `active_gate`');
        DB::statement(
            'ALTER TABLE `integration_connections` ADD COLUMN `active_gate` VARCHAR(70) '
            ."GENERATED ALWAYS AS (CASE WHEN `status` = 'active' "
            ."THEN CONCAT(`integration_provider_id`, ':', `environment`, ':', "
            .'COALESCE(`legal_entity_id`, 0)) ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `integration_connections` ADD UNIQUE KEY `uq_iconn_active` (`active_gate`)',
        );

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn('purpose_snapshot');
        });
    }

    /**
     * Los mismos dos disparadores de `9.17e`, ahora con una tarea más.
     *
     * Se reescriben enteros en vez de añadir un tercero: dos disparadores sobre
     * el mismo evento se ejecutan en un orden que nadie escribió, y el segundo
     * dependería de que el primero ya hubiera puesto `purpose_snapshot`.
     *
     * @return array<string, string> nombre => cuerpo
     */
    private static function disparadores(): array
    {
        $cuerpo = <<<'SQL'
            BEGIN
              DECLARE v_delProveedor INT DEFAULT 0;

              -- El proposito se COPIA del proveedor, siempre. No se admite el que
              -- venga en la sentencia: seria un sitio donde alguien podria poner
              -- otro y partir la puerta en dos.
              SET NEW.purpose_snapshot = (
                SELECT purpose FROM integration_providers WHERE id = NEW.integration_provider_id
              );

              IF NEW.status = 'active' THEN
                SELECT COUNT(*) INTO v_delProveedor
                  FROM integration_provider_endpoints
                 WHERE integration_provider_id = NEW.integration_provider_id
                   AND environment = NEW.environment;

                IF (NEW.base_url IS NULL OR NEW.base_url NOT LIKE 'https://%')
                   AND v_delProveedor = 0 THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Esa conexion no sabe a donde llamar: el proveedor no declara direccion para ese entorno.';
                END IF;

                IF NEW.purpose_snapshot = 'invoicing' AND NEW.legal_entity_id IS NULL THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Un emisor electronico va con una sociedad: es su RUC el que firma.';
                END IF;
              END IF;
            END
            SQL;

        return [
            'tg_iconn_activa_ins' => "BEFORE INSERT ON `integration_connections`\nFOR EACH ROW\n".$cuerpo,
            'tg_iconn_activa_upd' => "BEFORE UPDATE ON `integration_connections`\nFOR EACH ROW\n".$cuerpo,
        ];
    }
};
