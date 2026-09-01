<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El certificado con el que firma cada sociedad (9.9c).
 *
 * ### Por qué no cabe en `integration_credentials`
 *
 * `9.17d` guarda credenciales **de una conexión**: la clave con la que se llama
 * a un proveedor. Un certificado de firma no es eso. Es **la identidad de la
 * sociedad**: el mismo certificado firma tanto si el comprobante sale directo a
 * SUNAT como si sale por un proveedor, y sigue explicando la firma de lo ya
 * emitido cuando la conexión se cambia entera. Cuelga de `legal_entities`, no de
 * una conexión, y por eso tiene su tabla.
 *
 * Además tiene algo que ninguna credencial tiene: **vigencia propia, y no la
 * decide nadie de aquí**. Un certificado caduca en la fecha que lleva escrita
 * dentro, y el día que caduca deja de poder emitirse. Eso es un aviso con
 * antelación, no una sorpresa.
 *
 * ### Lo que se guarda es PEM, y no el `.pfx`
 *
 * El material se normaliza al subirlo: se lee el PKCS#12, se extraen el
 * certificado y la clave privada, y **se guarda el PEM cifrado**. Dos motivos:
 *
 * 1. Es lo que consume quien firma. Convertirlo en cada emisión sería repetir en
 *    caliente un trabajo que sólo hay que hacer una vez.
 * 2. **La contraseña del `.pfx` no se guarda.** Se usa una vez, al subirlo, y se
 *    olvida. Un secreto que no existe no se puede filtrar, y guardarlo no
 *    aportaría nada: el PEM ya está cifrado con la clave de la aplicación.
 *
 * ### Y no se borra
 *
 * `tg_cert_no_delete`. Un certificado caducado o reemplazado es lo que explica
 * la firma de las facturas de entonces —exactamente el mismo argumento que
 * `tg_tax_no_delete` en `9.9a`—. Se reemplaza o se revoca; nunca desaparece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_certificates', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('legal_entity_id');
            // `DEC-029` otra vez: el de pruebas y el real conviven y no se
            // pueden confundir. Firmar produccion con el certificado de beta
            // produce comprobantes que SUNAT rechaza.
            $table->string('environment', 15)->default('sandbox');

            // Lo que dice el propio certificado. NO se teclea: se lee.
            $table->string('subject_name', 255);
            $table->string('issuer_name', 255);
            $table->string('serial_number', 80);
            // El RUC que lleva DENTRO. Es lo que permite contestar «.este
            // certificado es de esta sociedad?» sin abrir el archivo.
            $table->string('tax_id_number', 40);
            $table->dateTime('valid_from', 3);
            $table->dateTime('valid_to', 3);
            $table->char('fingerprint_sha256', 64);

            // El certificado y su clave privada, en PEM y cifrados. Lo unico
            // que sale de aqui en claro lo pide quien firma, nadie mas.
            $table->longText('pem_cipher');
            // De donde vino. Un `.pem` subido tal cual y un `.pfx` convertido
            // producen la misma fila, y el dia que algo no cuadre conviene
            // saber por que camino entro.
            $table->string('source', 10)->default('pkcs12');

            $table->string('status', 15)->default('active');
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->dateTime('uploaded_at', 3);
            $table->dateTime('replaced_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_cert_uuid');
            // El mismo certificado no se sube dos veces PARA EL MISMO ENTORNO.
            // Con el entorno dentro, y no sin el: nada impide usar el mismo en
            // beta y en produccion, y prohibirlo seria inventar una regla.
            $table->unique(['fingerprint_sha256', 'environment'], 'uq_cert_huella');
            $table->index(['legal_entity_id', 'environment', 'status'], 'ix_cert_sociedad');
            $table->index('valid_to', 'ix_cert_vence');
            $table->index('uploaded_by_user_id', 'ix_cert_autor');

            $table->foreign('legal_entity_id', 'fk_cert_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
            $table->foreign('uploaded_by_user_id', 'fk_cert_autor')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // La 34.a columna puerta: UN solo certificado activo por sociedad y
        // entorno. Con dos, la mitad de los comprobantes iria firmado con uno y
        // la mitad con otro, y nadie sabria cual hasta que SUNAT rechazara.
        DB::statement(
            'ALTER TABLE `signing_certificates` ADD COLUMN `activo_gate` VARCHAR(45) '
            ."GENERATED ALWAYS AS (CASE WHEN `status` = 'active' "
            .'THEN CONCAT(`legal_entity_id`, \':\', `environment`) ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `signing_certificates` ADD UNIQUE KEY `uq_cert_activo` (`activo_gate`)',
        );

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

    public function down(): void
    {
        foreach (array_keys(self::disparadores()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('signing_certificates');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['signing_certificates', 'ck_cert_env', "environment IN ('sandbox','production')",
                ['environment'], 'Entorno no valido: pruebas o produccion.'],

            ['signing_certificates', 'ck_cert_status', "status IN ('active','replaced','revoked')",
                ['status'], 'Estado de certificado no valido.'],

            ['signing_certificates', 'ck_cert_dates', 'valid_to > valid_from',
                ['valid_to', 'valid_from'], 'Un certificado no caduca antes de empezar.'],

            // Un cifrado vacio es un certificado que no existe disfrazado de uno
            // que si: la pantalla diria «cargado» y la firma saldria sin clave.
            // Misma leccion que `ck_icred_cipher` en `9.17d`.
            ['signing_certificates', 'ck_cert_pem', "TRIM(pem_cipher) <> ''",
                ['pem_cipher'], 'Un certificado sin material no es un certificado.'],

            ['signing_certificates', 'ck_cert_huella', 'CHAR_LENGTH(fingerprint_sha256) = 64',
                ['fingerprint_sha256'], 'La huella de un certificado son 64 caracteres.'],

            ['signing_certificates', 'ck_cert_ruc', "TRIM(tax_id_number) <> ''",
                ['tax_id_number'], 'El certificado tiene que decir de que contribuyente es.'],

            ['signing_certificates', 'ck_cert_source', "source IN ('pkcs12','pem')",
                ['source'], 'Un certificado entra como PKCS#12 o como PEM.'],

            // La misma leccion que `document_numbers`, `client_leads` e
            // `invoices`: revocar sin motivo escrito no se puede defender.
            //
            // Y `revoked_reason IS NOT NULL` ANTES del largo, por CUARTA vez en
            // este proyecto: `CHAR_LENGTH(TRIM(NULL))` es NULL, la conjuncion
            // entera es NULL y un CHECK solo rechaza cuando es FALSO. Sin esa
            // mitad, revocar SIN NINGUN motivo pasaba --lo cazo la suite, no
            // yo--. A la cuarta se hizo un verificador: `verificar-nulos.py`.
            ['signing_certificates', 'ck_cert_revocado',
                "status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL"
                .' AND CHAR_LENGTH(TRIM(revoked_reason)) >= 10)',
                ['status', 'revoked_at', 'revoked_reason'],
                'Revocar un certificado exige decir por que.'],

            ['signing_certificates', 'ck_cert_reemplazado',
                "status <> 'replaced' OR replaced_at IS NOT NULL",
                ['status', 'replaced_at'],
                'Un certificado reemplazado dice cuando dejo de usarse.'],
        ];
    }

    /** @return array<string, string> nombre => cuerpo */
    private static function disparadores(): array
    {
        return [
            // Un certificado caducado explica la firma de las facturas de
            // entonces. Borrarlo deja esas firmas sin poder explicarse.
            'tg_cert_no_delete' => <<<'SQL'
                BEFORE DELETE ON `signing_certificates`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Un certificado no se borra: explica la firma de lo ya emitido.';
                END
                SQL,

            // Lo que dice el propio certificado no lo cambia nadie desde aqui.
            // Si el material cambiara, la huella dejaria de corresponder con lo
            // guardado y no habria forma de saber CON QUE se firmo.
            'tg_cert_inmutable' => <<<'SQL'
                BEFORE UPDATE ON `signing_certificates`
                FOR EACH ROW
                BEGIN
                  IF NOT (NEW.uuid <=> OLD.uuid)
                     OR NOT (NEW.legal_entity_id <=> OLD.legal_entity_id)
                     OR NOT (NEW.environment <=> OLD.environment)
                     OR NOT (NEW.subject_name <=> OLD.subject_name)
                     OR NOT (NEW.issuer_name <=> OLD.issuer_name)
                     OR NOT (NEW.serial_number <=> OLD.serial_number)
                     OR NOT (NEW.tax_id_number <=> OLD.tax_id_number)
                     OR NOT (NEW.valid_from <=> OLD.valid_from)
                     OR NOT (NEW.valid_to <=> OLD.valid_to)
                     OR NOT (NEW.fingerprint_sha256 <=> OLD.fingerprint_sha256)
                     OR NOT (NEW.pem_cipher <=> OLD.pem_cipher)
                     OR NOT (NEW.source <=> OLD.source)
                     OR NOT (NEW.uploaded_by_user_id <=> OLD.uploaded_by_user_id)
                     OR NOT (NEW.uploaded_at <=> OLD.uploaded_at)
                  THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un certificado no se reescribe: cargue el siguiente o revoquelo.';
                  END IF;
                END
                SQL,
        ];
    }
};
