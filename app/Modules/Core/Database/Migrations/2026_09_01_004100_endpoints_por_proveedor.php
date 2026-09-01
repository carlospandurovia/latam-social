<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La dirección de un proveedor no se teclea (9.17e).
 *
 * ### El defecto, tal y como lo vio el negocio
 *
 * > «¿por qué me pide la URL? se supone que si selecciono Pruebas debe ir al URL
 * > Beta, si selecciono PRD debe ir al de PRD, ¿por qué no lo veo así?»
 *
 * Tiene razón, y era un defecto de `9.17d`. **Los extremos de SUNAT son fijos y
 * públicos**: no son un dato de esta instalación, son la dirección del servicio,
 * la misma para todo el mundo. Pedírselos a una persona es pedirle que teclee
 * una constante — y un carácter de más produce comprobantes que no llegan, con
 * un error de red que no dice qué pasó.
 *
 * Es exactamente `DEC-190` al revés: allí el problema era **quemar en el código
 * lo que es de cada instalación**; aquí era **pedirle a cada instalación lo que
 * es del proveedor**. El código pone la regla —*una conexión activa sabe a dónde
 * llamar*—; el valor lo pone el catálogo del proveedor.
 *
 * ### Sigue habiendo columna en la conexión, y es a propósito
 *
 * `integration_connections.base_url` se queda como **excepción**: vacía significa
 * «la del proveedor para ese entorno». Un OSE con su propio dominio, un servidor
 * de homologación de una empresa concreta o una URL que SUNAT cambie un martes
 * se resuelven escribiéndola ahí, sin desplegar y sin tocar el catálogo.
 *
 * ### Y `ck_iconn_url` se convierte en disparador
 *
 * Porque ahora la pregunta es cruzada: «¿esta conexión sabe a dónde llamar?» se
 * contesta mirando **otra tabla**. Un CHECK no puede. De paso el disparador
 * responde la segunda pregunta que faltaba —**un emisor electrónico va con una
 * sociedad**, porque lleva su RUC— y las dos sólo se exigen para **activar**: un
 * borrador es justamente el sitio donde todavía faltan cosas (`DEC-190`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_provider_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_provider_id');
            $table->string('environment', 15);
            $table->string('base_url', 255);
            // Como lo llama el proveedor en su documentacion. Lo que se ensena
            // junto a la URL para que quien elige entorno reconozca el suyo.
            $table->string('label', 60)->nullable();
            $table->string('notes', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            // Una direccion por proveedor y entorno. Con dos, la mitad de las
            // llamadas iria a una y la mitad a otra.
            $table->unique(['integration_provider_id', 'environment'], 'uq_ipend_entorno');

            $table->foreign('integration_provider_id', 'fk_ipend_provider')
                ->references('id')->on('integration_providers')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // La regla deja de ser de una fila y pasa a ser cruzada.
        Restriccion::quitar('integration_connections', 'ck_iconn_url');

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::disparadores()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }

        Restriccion::comprobacion(
            tabla: 'integration_connections', nombre: 'ck_iconn_url',
            expresion: "status <> 'active' OR (base_url IS NOT NULL AND base_url LIKE 'https://%')",
            columnas: ['status', 'base_url'],
            mensaje: 'Una conexion activa tiene que saber a donde llamar, y por https.',
        );

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('integration_provider_endpoints');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['integration_provider_endpoints', 'ck_ipend_env',
                "environment IN ('sandbox','production')",
                ['environment'], 'Entorno no valido: pruebas o produccion.'],

            // La misma exigencia que tenia `ck_iconn_url`, ahora donde vive el
            // valor: un extremo por http manda las claves en claro.
            ['integration_provider_endpoints', 'ck_ipend_url', "base_url LIKE 'https://%'",
                ['base_url'], 'La direccion de un proveedor va por https.'],
        ];
    }

    /** @return array<string, string> nombre => cuerpo */
    private static function disparadores(): array
    {
        $cuerpo = <<<'SQL'
            BEGIN
              DECLARE v_proposito VARCHAR(30);
              DECLARE v_delProveedor INT DEFAULT 0;

              IF NEW.status = 'active' THEN
                SELECT purpose INTO v_proposito
                  FROM integration_providers WHERE id = NEW.integration_provider_id;

                SELECT COUNT(*) INTO v_delProveedor
                  FROM integration_provider_endpoints
                 WHERE integration_provider_id = NEW.integration_provider_id
                   AND environment = NEW.environment;

                -- Sabe a donde llamar: la suya, o la que el proveedor declara
                -- para ese entorno.
                IF (NEW.base_url IS NULL OR NEW.base_url NOT LIKE 'https://%')
                   AND v_delProveedor = 0 THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Esa conexion no sabe a donde llamar: el proveedor no declara direccion para ese entorno.';
                END IF;

                -- Un emisor electronico va con una sociedad: lleva su RUC.
                IF v_proposito = 'invoicing' AND NEW.legal_entity_id IS NULL THEN
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
