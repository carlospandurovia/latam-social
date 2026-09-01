<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta de correo deja de vivir en el servidor (9.17g).
 *
 * ### Por qué era lo que más dolía
 *
 * Hasta hoy el correo se configuraba en `.env`, y eso significa **entrar a la
 * máquina**. Es el único ajuste del sistema que exigía un despliegue para algo
 * que cambia solo —una cuenta que caduca, un proveedor que se cambia— y el que
 * más caro sale tener mal: con el transporte en «log» **nadie recibe nada** —ni
 * el enlace de alta de un creador— y el sistema **no da ningún error**.
 *
 * ### Y por qué es una tabla propia y no columnas en la conexión
 *
 * Es `DEC-257` aplicado: el esqueleto —quién, dónde, estado, credencial, salud—
 * se queda en `integration_connections`, que ya sabe rotar un secreto sin
 * pisarlo y ya impide dos activas del mismo propósito. Lo que **sólo le importa
 * al correo** —servidor, puerto, cifrado, remitente— vive aquí. Un puerto SMTP
 * en la tabla de conexiones no le sirve a nadie más.
 *
 * ### La precedencia se escribe, y se enseña
 *
 * Manda **la conexión activa** si existe; si no, `.env`. Y la pantalla dice
 * cuál está en efecto: una precedencia que no se ve es de las cosas que más
 * horas hacen perder —se cambia un valor, no pasa nada, y nadie sabe por qué—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->id();
            // Cuelga de la conexion: de ahi salen el entorno, el estado, la
            // credencial y el registro de salud. UNICA porque una conexion de
            // correo tiene una sola configuracion.
            $table->foreignId('integration_connection_id');
            $table->string('host', 160);
            $table->unsignedSmallInteger('port');
            // NULL = sin cifrar. Se admite porque un servidor de pruebas local
            // no lo lleva, y se avisa EN ROJO porque en cualquier otro sitio
            // significa mandar la contrasena en claro por la red.
            $table->string('encryption', 10)->nullable();
            $table->string('from_address', 255);
            $table->string('from_name', 120);
            // Un servidor que no contesta no puede colgar una peticion web.
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('integration_connection_id', 'uq_mail_conexion');

            $table->foreign('integration_connection_id', 'fk_mail_conn')
                ->references('id')->on('integration_connections')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    /**
     * La regla de «sabe a dónde llamar», corregida.
     *
     * `9.17e` la escribió como *«tiene una URL `https://`»*, y eso era mirar
     * sólo a SUNAT: **un servidor de correo no tiene URL**, tiene servidor y
     * puerto. Con la regla anterior, una cuenta de correo **no se podía
     * activar** — lo descubrió la primera prueba que intentó guardarla.
     *
     * Se parte en las dos cosas que de verdad decía:
     *
     * 1. **Tiene alguna dirección** — la suya o la que declare el proveedor.
     * 2. **Y si es una dirección web, va cifrada** — `http://` se rechaza, que
     *    era el motivo de seguridad; `smtp://` no es asunto de esta regla, y de
     *    que el correo vaya cifrado avisa `CuentaDeCorreo` en rojo.
     *
     * @return array<string, string> nombre => cuerpo
     */
    private static function disparadores(): array
    {
        $cuerpo = <<<'SQL'
            BEGIN
              DECLARE v_delProveedor INT DEFAULT 0;

              SET NEW.purpose_snapshot = (
                SELECT purpose FROM integration_providers WHERE id = NEW.integration_provider_id
              );

              IF NEW.status = 'active' THEN
                SELECT COUNT(*) INTO v_delProveedor
                  FROM integration_provider_endpoints
                 WHERE integration_provider_id = NEW.integration_provider_id
                   AND environment = NEW.environment;

                IF (NEW.base_url IS NULL OR TRIM(NEW.base_url) = '') AND v_delProveedor = 0 THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Esa conexion no sabe a donde llamar: el proveedor no declara direccion para ese entorno.';
                END IF;

                IF NEW.base_url LIKE 'http://%' THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Una direccion web sin cifrar manda las claves en claro: use https.';
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

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('mail_settings');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['mail_settings', 'ck_mail_host', "TRIM(host) <> ''",
                ['host'], 'El servidor de correo necesita una direccion.'],

            ['mail_settings', 'ck_mail_port', 'port BETWEEN 1 AND 65535',
                ['port'], 'El puerto va entre 1 y 65535.'],

            // `encryption IS NULL OR ...` a proposito: sin cifrar es un estado
            // valido --un servidor local de pruebas-- y se avisa, no se impide.
            ['mail_settings', 'ck_mail_cifrado', "encryption IS NULL OR encryption IN ('tls','ssl')",
                ['encryption'], 'El cifrado del correo es tls, ssl o ninguno.'],

            ['mail_settings', 'ck_mail_remitente', "from_address LIKE '%_@_%'",
                ['from_address'], 'La direccion del remitente no tiene forma de correo.'],

            ['mail_settings', 'ck_mail_nombre', 'CHAR_LENGTH(TRIM(from_name)) >= 2',
                ['from_name'], 'El remitente necesita un nombre que se pueda leer.'],

            // Un servidor que no contesta no puede colgar una peticion web
            // durante minutos: quien esta esperando cree que el sistema murio.
            ['mail_settings', 'ck_mail_espera', 'timeout_seconds BETWEEN 1 AND 120',
                ['timeout_seconds'], 'La espera del servidor de correo va entre 1 y 120 segundos.'],
        ];
    }
};
