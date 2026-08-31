<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las credenciales de cada API, en un solo sitio (9.17d).
 *
 * ### De dónde sale
 *
 * `Q-03`, con las palabras del negocio: *«Se facturará con facturación directa
 * con SUNAT… URL, Certificado, usuario y clave secundarias, correlativos…
 * configurables desde el admin»*. Y `DEC-033` / `docs/12`, que ya decidieron que
 * eso **no vive en `legal_entities`**: una sociedad es quién factura; una
 * conexión es con qué se conecta uno a un proveedor, y cambia mucho más a
 * menudo.
 *
 * Es el prerrequisito de `9.9`: sin un sitio donde poner la URL y las claves
 * secundarias de SUNAT, la facturación electrónica no tiene de dónde leerlas.
 *
 * ### Lo que se construye ahora y lo que NO
 *
 * `docs/12` describe cinco tablas: proveedores, conexiones, **asignaciones**,
 * credenciales, eventos de webhook y registro de llamadas. Aquí van **tres**.
 *
 * Las **asignaciones** —el eje `(marca, sociedad, país)` con vigencia— resuelven
 * un problema que todavía no existe: hoy una conexión sirve a una sociedad o a
 * la plataforma entera, y eso cabe en una columna `legal_entity_id` nullable
 * donde `NULL` significa «de la plataforma». Construir la tabla de tres ejes
 * antes de tener el segundo caso es construir contra un supuesto. Cuando haya
 * dos proveedores de facturación conviviendo, esa tabla se hace y esta columna
 * se migra a ella; queda escrito para que no se tome por un olvido.
 *
 * Los **webhooks** y el **registro de llamadas** llegan con el proveedor que los
 * necesite: no hay a quién registrarle nada todavía.
 *
 * ### El secreto se escribe y no se vuelve a leer
 *
 * `integration_credentials` guarda el cifrado y **los cuatro últimos caracteres,
 * nada más** para la pantalla. Rotar una credencial **crea una versión nueva y
 * cierra la anterior**; no la sobrescribe (`docs/12` §3.2). Así se puede volver
 * atrás si la nueva es incorrecta, y se puede contestar «¿cuándo cambió?».
 *
 * `uq_icred_vigente` es otra columna puerta --las cuenta
 * `verificar-nombres-sql.py`, no este comentario (`T-57`)--: una sola credencial
 * viva por (conexión, clase). Con dos, la mitad de las llamadas iría con una y
 * la otra mitad con otra, y el síntoma sería un proveedor rechazando una de cada
 * dos peticiones sin patrón aparente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El catalogo. Es una tabla y no una constante porque el dia que entre
        // un proveedor nuevo --otro emisor electronico, una pasarela-- eso es
        // una fila, no un despliegue (`DEC-190`).
        Schema::create('integration_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40);
            $table->string('name', 120);
            // Para que sirve: `invoicing`, `fx`, `email`, `payment`. Agrupa la
            // pantalla y, mas adelante, permite preguntar «quien factura en PE».
            $table->string('purpose', 30);
            $table->string('doc_url', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('code', 'uq_iprov_code');
            $table->index(['purpose', 'is_active'], 'ix_iprov_purpose');
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('integration_provider_id');
            // NULL = de la plataforma entera (el correo, los tipos de cambio).
            // Con sociedad = de esa sociedad (el emisor electronico, que va con
            // el RUC y el certificado de quien factura).
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('name', 120);
            $table->string('environment', 15)->default('sandbox');
            $table->string('base_url', 255)->nullable();
            // El usuario secundario de SUNAT y equivalentes: NO es un secreto
            // --se ensena entero-- y por eso no esta en `integration_credentials`.
            $table->string('username', 120)->nullable();
            $table->string('status', 15)->default('draft');
            // Lo que contesta «.funciona?» sin tener que probarlo: cuando se
            // comprobo por ultima vez, cuando fue el ultimo exito y cual fue el
            // ultimo error. Sin esto, el estado de una integracion es una
            // conjetura hasta que algo falla de cara al cliente.
            $table->dateTime('last_verified_at', 3)->nullable();
            $table->dateTime('last_success_at', 3)->nullable();
            $table->dateTime('last_error_at', 3)->nullable();
            $table->string('last_error_message', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_iconn_uuid');
            $table->index(['integration_provider_id', 'status'], 'ix_iconn_provider');
            $table->index('legal_entity_id', 'ix_iconn_entity');

            $table->foreign('integration_provider_id', 'fk_iconn_provider')
                ->references('id')->on('integration_providers')->restrictOnDelete();
            $table->foreign('legal_entity_id', 'fk_iconn_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
        });

        // Una sola conexion ACTIVA por (proveedor, entorno, sociedad). Con dos,
        // resolver «con que se factura» tendria un empate, y los empates se
        // rechazan al guardar la configuracion y no al intentar emitir --que es
        // el criterio de `uq_lec_country` desde 2.10--.
        //
        // `COALESCE(legal_entity_id, 0)` porque en un indice unico dos NULL NO
        // colisionan: sin esto se podrian tener dos conexiones de plataforma
        // activas del mismo proveedor, que es justo lo que se quiere impedir.
        DB::statement(
            'ALTER TABLE `integration_connections` ADD COLUMN `active_gate` VARCHAR(70) '
            ."GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN CONCAT("
            .'`integration_provider_id`, \':\', `environment`, \':\', '
            .'COALESCE(`legal_entity_id`, 0)) ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `integration_connections` ADD UNIQUE KEY `uq_iconn_active` (`active_gate`)',
        );

        Schema::create('integration_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id');
            // Que secreto es: `api_key`, `password`, `webhook_secret`, `token`.
            $table->string('kind', 30);
            // El cifrado. `Crypt::encryptString`, como la clave de la fuente de
            // tipos de cambio desde 9.2: misma tecnica, mismo motivo.
            $table->text('secret_cipher');
            // Lo UNICO que vuelve a una pantalla. La credencial entera no se
            // devuelve nunca: se reemplaza.
            $table->string('last4', 8)->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->unsignedBigInteger('set_by_user_id');
            $table->dateTime('set_at', 3);
            // NULL = viva. Rotar cierra la anterior y crea la siguiente.
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['integration_connection_id', 'kind'], 'ix_icred_conn');
            $table->index('set_by_user_id', 'ix_icred_autor');

            // RESTRICT y no CASCADE, y no es preferencia: **MySQL 8 rechaza
            // con un `1215` anadir una columna generada sobre una columna que
            // participa en una foranea con accion en cascada**, y la puerta de
            // abajo se construye justo sobre esta. MariaDB lo admite y el
            // esquema de referencia cargo sin quejarse; la migracion, sobre
            // MySQL 8, no. Los dos motores hacen falta para verlo.
            //
            // Y ademas es lo correcto aqui: en este proyecto nada se borra en
            // cascada. Una credencial cuenta que se configuro y cuando; borrar
            // la conexion no puede llevarse esa respuesta por delante.
            $table->foreign('integration_connection_id', 'fk_icred_conn')
                ->references('id')->on('integration_connections')->restrictOnDelete();
            $table->foreign('set_by_user_id', 'fk_icred_autor')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Otra columna puerta: una sola credencial VIVA por (conexion, clase).
        DB::statement(
            'ALTER TABLE `integration_credentials` ADD COLUMN `vigente_gate` VARCHAR(45) '
            .'GENERATED ALWAYS AS (CASE WHEN `revoked_at` IS NULL THEN CONCAT('
            .'`integration_connection_id`, \':\', `kind`) ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `integration_credentials` ADD UNIQUE KEY `uq_icred_vigente` (`vigente_gate`)',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Una credencial no se reescribe: se revoca y se crea la siguiente. Sin
        // esto, «rotar» podria ser un UPDATE sobre el cifrado, y entonces la
        // pregunta «.cuando cambio y quien la puso?» deja de tener respuesta
        // -- que es la mitad del motivo de que esta tabla exista.
        DB::statement('DROP TRIGGER IF EXISTS `tg_icred_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_icred_inmutable`
            BEFORE UPDATE ON `integration_credentials`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`secret_cipher` <=> OLD.`secret_cipher`)
                   OR NOT (NEW.`kind` <=> OLD.`kind`)
                   OR NOT (NEW.`integration_connection_id` <=> OLD.`integration_connection_id`)
                   OR NOT (NEW.`set_by_user_id` <=> OLD.`set_by_user_id`)
                   OR NOT (NEW.`set_at` <=> OLD.`set_at`) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Una credencial no se reescribe: revoquela y guarde la siguiente.';
                END IF;

                IF OLD.`revoked_at` IS NOT NULL AND NEW.`revoked_at` IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Una credencial revocada no vuelve a estar viva.';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_icred_inmutable`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('integration_credentials');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('integration_providers');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['integration_providers', 'ck_iprov_purpose',
                "purpose IN ('invoicing','fx','email','payment','identity','other')",
                ['purpose'], 'Proposito de integracion no valido.'],

            // La barrera de `DEC-029` aplicada a las conexiones: la de pruebas y
            // la real conviven y no se pueden confundir.
            ['integration_connections', 'ck_iconn_env', "environment IN ('sandbox','production')",
                ['environment'], 'Entorno de conexion no valido: sandbox o production.'],

            ['integration_connections', 'ck_iconn_status',
                "status IN ('draft','active','disabled')",
                ['status'], 'Estado de conexion no valido.'],

            // Una conexion ACTIVA tiene que saber a donde llamar. En borrador
            // no: un borrador es justamente el sitio donde todavia faltan cosas.
            ['integration_connections', 'ck_iconn_url',
                "status <> 'active' OR (base_url IS NOT NULL AND base_url LIKE 'https://%')",
                ['status', 'base_url'],
                'Una conexion activa necesita una URL https a la que llamar.'],

            ['integration_credentials', 'ck_icred_kind',
                "kind IN ('api_key','password','token','webhook_secret','client_secret')",
                ['kind'], 'Clase de credencial no valida.'],

            // Un cifrado vacio es una credencial que no existe disfrazada de
            // credencial que si: la pantalla diria «configurada» y la llamada
            // saldria sin clave.
            ['integration_credentials', 'ck_icred_cipher', "TRIM(secret_cipher) <> ''",
                ['secret_cipher'], 'Una credencial vacia no es una credencial.'],

            // Media revocacion no vale: si esta revocada, consta por que.
            ['integration_credentials', 'ck_icred_revocada',
                'revoked_at IS NULL OR revoked_reason IS NOT NULL',
                ['revoked_at', 'revoked_reason'],
                'Una credencial revocada tiene que decir por que.'],
        ];
    }
};
