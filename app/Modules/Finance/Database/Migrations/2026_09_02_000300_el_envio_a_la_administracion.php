<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada envío a la administración deja rastro (9.9e).
 *
 * ### Por qué una tabla de INTENTOS y no un estado en la factura
 *
 * `invoices.external_status` dice **cómo está ahora**. Esta tabla dice **qué ha
 * pasado**, y son dos preguntas distintas que se hacen en momentos distintos:
 *
 * - «¿está aceptada?» → la factura.
 * - «¿por qué tardó tres días?», «¿cuántas veces se reintentó?», «¿qué contestó
 *   SUNAT la primera vez?» → esto.
 *
 * La segunda es la que se hace cuando algo va mal, que es cuando hace falta. Con
 * sólo el estado, cada reintento **borra** la respuesta anterior, y la única
 * copia de «SUNAT dijo que el RUC del cliente no existe» desaparece en cuanto
 * alguien vuelve a darle al botón.
 *
 * ### No se borra y no se cambia
 *
 * Es la misma regla que `ledger_entries` y que `electronic_documents`: un
 * intento es un hecho, y los hechos no se editan. Reintentar añade una fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_submissions', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('uuid');
            $tabla->unsignedBigInteger('invoice_id');
            // Que documento se mando. Si se regenero el XML entre dos intentos,
            // esto dice cual de los dos vio la administracion --y esa es
            // exactamente la pregunta que `DEC-271` existe para poder contestar.
            $tabla->unsignedBigInteger('electronic_document_id');
            $tabla->unsignedSmallInteger('attempt_number');
            $tabla->string('outcome', 20);
            // Lo que contesto, tal cual. `NULL` cuando no se llego a saber.
            $tabla->string('response_code', 10)->nullable();
            $tabla->string('response_message', 255)->nullable();
            $tabla->unsignedSmallInteger('notes_count')->default(0);
            // CON QUE conexion se hablo, por su nombre. NO la clave: el nombre,
            // que es lo que permite contestar «.contra que entorno se mando?».
            $tabla->string('connection_snapshot', 60)->nullable();
            $tabla->string('environment', 20);
            $tabla->unsignedInteger('duration_ms')->nullable();
            $tabla->dateTime('sent_at', 3);
            $tabla->unsignedBigInteger('sent_by_user_id');
            $tabla->dateTime('created_at', 3)->nullable();
            $tabla->dateTime('updated_at', 3)->nullable();

            $tabla->unique('uuid', 'uq_dsub_uuid');
            // Un numero de intento por factura: dos filas con el mismo numero
            // significarian que dos envios simultaneos se pisaron.
            $tabla->unique(['invoice_id', 'attempt_number'], 'uq_dsub_intento');
            $tabla->index(['invoice_id', 'sent_at'], 'ix_dsub_factura');
            $tabla->index('electronic_document_id', 'ix_dsub_documento');
            $tabla->index('sent_by_user_id', 'ix_dsub_autor');
            $tabla->index(['outcome', 'sent_at'], 'ix_dsub_resultado');

            $tabla->foreign('invoice_id', 'fk_dsub_invoice')
                ->references('id')->on('invoices')->restrictOnDelete();
            $tabla->foreign('electronic_document_id', 'fk_dsub_edoc')
                ->references('id')->on('electronic_documents')->restrictOnDelete();
            $tabla->foreign('sent_by_user_id', 'fk_dsub_autor')
                ->references('id')->on('users')->restrictOnDelete();
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

    /** @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string}> */
    private static function restricciones(): array
    {
        return [
            ['document_submissions', 'ck_dsub_outcome',
                "outcome IN ('aceptado','observado','rechazado','error_red','no_configurado')",
                ['outcome'], 'Resultado de envio no valido.'],
            // Los cinco finales exigen cinco arreglos distintos. Uno que no
            // esta en la lista es un final que nadie sabe como arreglar.
            ['document_submissions', 'ck_dsub_intento', 'attempt_number >= 1',
                ['attempt_number'], 'El primer intento es el numero uno.'],
            // Un final que dice que hablo con la administracion tiene que traer
            // lo que contesto. Sin esto, «aceptado» sin codigo pasaria, y
            // «.donde esta el CDR?» no tendria respuesta.
            ['document_submissions', 'ck_dsub_contesto',
                "outcome IN ('error_red','no_configurado') OR response_code IS NOT NULL",
                ['outcome', 'response_code'],
                'Si la administracion contesto, se guarda lo que dijo.'],
            // 9.9e: `invoices.external_status` existia desde la Fase 2 SIN
            // vocabulario --VARCHAR(30) NULL y a escribir lo que fuera--. Ahora
            // que hay cinco finales con significado, se cierra: un estado que
            // nadie sabe leer no dice nada, y el sitio donde se descubre eso es
            // una pantalla que no sabe que pintar.
            ['invoices', 'ck_invoice_external',
                'external_status IS NULL OR external_status IN '
                ."('aceptado','observado','rechazado','error_red','no_configurado')",
                ['external_status'], 'Estado ante la administracion no valido.'],
            ['document_submissions', 'ck_dsub_notas',
                "notes_count = 0 OR outcome IN ('observado','aceptado')",
                ['notes_count', 'outcome'],
                'Solo un envio que entro puede traer observaciones.'],
        ];
    }

    /**
     * Un intento es un hecho: ni se borra ni se cambia.
     *
     * @return array<string, string>
     */
    private static function disparadores(): array
    {
        return [
            'tg_dsub_no_delete' => <<<'SQL'
              BEFORE DELETE ON `document_submissions`
              FOR EACH ROW
              BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Un envio a la administracion no se borra: es lo que explica que paso.';
              END
            SQL,
            'tg_dsub_no_update' => <<<'SQL'
              BEFORE UPDATE ON `document_submissions`
              FOR EACH ROW
              BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Un envio no se corrige: se reintenta, y eso anade una fila.';
              END
            SQL,
        ];
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_dsub_no_delete`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_dsub_no_update`');
        Schema::dropIfExists('document_submissions');
    }
};
