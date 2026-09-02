<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde vive el XML firmado de un comprobante (9.9d).
 *
 * ### Por qué el XML va EN LA BASE y no en `files`
 *
 * `files` existe desde la Fase 2 y es donde van los archivos: los entregables
 * de un creador, un logotipo, un PDF. Este no es un archivo cualquiera — es
 * **la prueba firmada de una operación**, y tres cosas la separan del resto:
 *
 * 1. **Tiene que sobrevivir exactamente como se firmó.** Un byte distinto y la
 *    firma deja de validar. En la base entra en la copia de seguridad de la
 *    base, junto a la factura que describe; en disco vive en otro sitio, con
 *    otra política de copia, y se pierde por separado.
 * 2. **Es pequeño.** Un comprobante son 6 KB. La razón de sacar archivos de la
 *    base —que ocupan— aquí no aplica.
 * 3. **Una instalación sin disco configurado tiene que poder emitir bien.** Si
 *    la validez fiscal dependiera de que alguien configuró S3, habría
 *    instalaciones emitiendo y perdiendo la prueba sin enterarse.
 *
 * El PDF, cuando llegue, sí irá a `files`: se puede volver a generar.
 *
 * ### Una sola vigente, y las anteriores no se borran
 *
 * Regenerar un XML es legítimo —se corrigió el ubigeo, se cambió el
 * certificado— pero **el anterior no desaparece**: se marca como reemplazado,
 * con su fecha. Si ya se había mandado a SUNAT, lo que ellos tienen es el
 * viejo, y perderlo es perder la única copia de lo que se declaró.
 *
 * La 35.ª columna puerta lo garantiza con un índice único y no con un `COUNT`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_documents', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('uuid');
            $tabla->unsignedBigInteger('invoice_id');
            // `xml_signed` hoy; `cdr` en 9.9e, cuando SUNAT conteste. Es la
            // misma tabla porque es la misma pregunta --«que documentos
            // electronicos tiene esta factura?»--.
            $tabla->string('kind', 20);
            // El nombre que exige la administracion. Se guarda porque es parte
            // de la identidad del documento, no un detalle de presentacion.
            $tabla->string('name', 120);
            $tabla->mediumText('xml_content');
            $tabla->char('sha256', 64);
            $tabla->unsignedInteger('size_bytes');
            // Con que certificado se firmo. NO la clave: la huella, que es lo
            // que permite contestar «.con cual se firmo esto?» el dia que haya
            // dos y uno este revocado.
            $tabla->unsignedBigInteger('signing_certificate_id')->nullable();
            $tabla->dateTime('generated_at', 3);
            $tabla->unsignedBigInteger('generated_by_user_id');
            $tabla->dateTime('superseded_at', 3)->nullable();
            $tabla->dateTime('created_at', 3)->nullable();
            $tabla->dateTime('updated_at', 3)->nullable();

            $tabla->unique('uuid', 'uq_edoc_uuid');
            $tabla->index(['invoice_id', 'kind'], 'ix_edoc_factura');
            $tabla->index('sha256', 'ix_edoc_huella');
            $tabla->index('generated_by_user_id', 'ix_edoc_autor');
            $tabla->index('signing_certificate_id', 'ix_edoc_cert');

            $tabla->foreign('invoice_id', 'fk_edoc_invoice')
                ->references('id')->on('invoices')->restrictOnDelete();
            $tabla->foreign('generated_by_user_id', 'fk_edoc_autor')
                ->references('id')->on('users')->restrictOnDelete();
            $tabla->foreign('signing_certificate_id', 'fk_edoc_cert')
                ->references('id')->on('signing_certificates')->restrictOnDelete();
        });

        // Columna puerta 35: UNO vigente por factura y clase.
        DB::statement(
            'ALTER TABLE `electronic_documents` ADD COLUMN `vigente_gate` VARCHAR(45) '
            .'GENERATED ALWAYS AS (CASE WHEN `superseded_at` IS NULL '
            ."THEN CONCAT(`invoice_id`, ':', `kind`) ELSE NULL END) STORED",
        );
        DB::statement(
            'ALTER TABLE `electronic_documents` ADD UNIQUE KEY `uq_edoc_vigente` (`vigente_gate`)',
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

    /** @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string}> */
    private static function restricciones(): array
    {
        return [
            ['electronic_documents', 'ck_edoc_kind', "kind IN ('xml_signed','cdr')",
                ['kind'], 'Clase de documento electronico no valida.'],
            // 64 caracteres exactos: un sha256 en hexadecimal. Una huella
            // truncada no sirve para comprobar nada y parece que si.
            ['electronic_documents', 'ck_edoc_huella',
                "CHAR_LENGTH(sha256) = 64 AND sha256 REGEXP '^[0-9a-f]{64}$'",
                ['sha256'], 'La huella de un documento son 64 caracteres hexadecimales.'],
            ['electronic_documents', 'ck_edoc_vacio', 'size_bytes > 0',
                ['size_bytes'], 'Un documento electronico vacio no es un documento.'],
            ['electronic_documents', 'ck_edoc_nombre',
                'CHAR_LENGTH(TRIM(name)) >= 5',
                ['name'], 'Un documento electronico va con el nombre que exige la administracion.'],
        ];
    }

    /**
     * Lo que se firmó no se toca.
     *
     * Es la misma regla que `ledger_entries` (3.x) y que `invoices` desde
     * `9.9b`, y aquí pesa más: el XML **es** la prueba. Si se pudiera editar,
     * la firma que lleva dentro dejaría de significar nada — que es justo lo
     * contrario de para lo que se firma.
     *
     * Lo único que puede cambiar es `superseded_at`, y sólo de vacío a puesto:
     * es cómo se dice «este ya no es el vigente» sin borrar el que se mandó.
     *
     * @return array<string, string>
     */
    private static function disparadores(): array
    {
        return [
            'tg_edoc_no_delete' => <<<'SQL'
              BEFORE DELETE ON `electronic_documents`
              FOR EACH ROW
              BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Un comprobante firmado no se borra: es la prueba de lo que se declaro.';
              END
            SQL,
            'tg_edoc_inmutable' => <<<'SQL'
              BEFORE UPDATE ON `electronic_documents`
              FOR EACH ROW
              BEGIN
                IF NEW.`xml_content` <> OLD.`xml_content`
                   OR NEW.`sha256` <> OLD.`sha256`
                   OR NEW.`name` <> OLD.`name`
                   OR NEW.`invoice_id` <> OLD.`invoice_id`
                   OR NEW.`kind` <> OLD.`kind`
                   OR NEW.`generated_at` <> OLD.`generated_at` THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Lo que se firmo no se cambia: se genera otro y este queda reemplazado.';
                END IF;

                IF OLD.`superseded_at` IS NOT NULL AND NEW.`superseded_at` IS NULL THEN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Un documento reemplazado no vuelve a ser el vigente.';
                END IF;
              END
            SQL,
        ];
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_edoc_no_delete`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_edoc_inmutable`');
        Schema::dropIfExists('electronic_documents');
    }
};
