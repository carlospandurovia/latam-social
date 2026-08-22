<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La otra mitad de DEC-016: quiénes somos y quién factura.
 *
 * `PlatformBrand` (LATAM Social) es cómo nos llamamos. `LegalEntity` es la
 * sociedad que emite el comprobante. Confundirlas produce facturas emitidas por
 * la sociedad equivocada, que no se corrigen con un UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_brands', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('legal_footer', 255)->nullable();
            $table->unsignedBigInteger('logo_file_id')->nullable();
            $table->char('primary_color', 7)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('support_email', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_pb_uuid');
            $table->unique('code', 'uq_pb_code');
            $table->index('logo_file_id', 'ix_pb_logo');

            $table->foreign('logo_file_id', 'fk_pb_logo')
                ->references('id')->on('files')->restrictOnDelete();
        });

        // NO lleva ruta de certificado ni credenciales de SUNAT: eso es una
        // conexión de integración (docs/12, DEC-033). Fue una autocorrección de
        // la Fase 0 y conviene que siga siéndolo.
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('platform_brand_id');
            $table->string('code', 30);
            $table->string('legal_name', 200);
            $table->string('trade_name', 160)->nullable();
            $table->foreignId('country_id');
            $table->string('tax_id_type', 20);
            $table->string('tax_id_number', 40);
            $table->string('address_line1', 180);
            $table->string('address_line2', 180)->nullable();
            $table->string('city', 100);
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('default_currency_code', 3);
            // Convierte un instante UTC en "el día" que exige el comprobante
            // fiscal (docs 2.3 §8). Sin esto, una factura de fin de mes cae en
            // el periodo tributario equivocado.
            $table->string('timezone', 64);
            $table->string('legal_representative', 160)->nullable();
            $table->string('status', 15)->default('active');
            $table->date('incorporated_on')->nullable();
            $table->date('dissolved_on')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_le_uuid');
            $table->unique('code', 'uq_le_code');
            // Dos sociedades no pueden compartir identificador fiscal en un país.
            $table->unique(['country_id', 'tax_id_type', 'tax_id_number'], 'uq_le_taxid');
            $table->index(['platform_brand_id', 'status'], 'ix_le_brand');
            $table->index('default_currency_code', 'ix_le_currency');

            $table->foreign('platform_brand_id', 'fk_le_brand')
                ->references('id')->on('platform_brands')->restrictOnDelete();
            $table->foreign('country_id', 'fk_le_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('default_currency_code', 'fk_le_currency')
                ->references('code')->on('currencies')->restrictOnDelete();
        });

        // docs/11: N:M con VIGENCIA, no un booleano. La cobertura cambia y el
        // histórico manda: una factura de marzo la emitió quien cubría en marzo.
        Schema::create('legal_entity_countries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id');
            $table->foreignId('country_id');
            // Por qué esta sociedad cubre este país (sociedad local, exportación
            // de servicios, sucursal). Es lo que un auditor pregunta primero.
            $table->string('coverage_basis', 40)->default('service_export');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['legal_entity_id', 'country_id'], 'ix_lec_entity');

            $table->foreign('legal_entity_id', 'fk_lec_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
            $table->foreign('country_id', 'fk_lec_country')
                ->references('id')->on('countries')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE legal_entity_countries ADD COLUMN current_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED'
        );
        // UNA sola sociedad vigente por país. Sin esto el resolver de facturación
        // tendría un empate, y docs 2.2 ya decidió que los empates se rechazan al
        // guardar la configuración, no al intentar emitir la factura.
        DB::statement(
            'ALTER TABLE legal_entity_countries ADD UNIQUE KEY uq_lec_country (current_gate, country_id)'
        );

        // SUNAT exige serie + correlativo sin huecos por tipo de documento. El
        // número se RESERVA aquí, no se calcula con MAX(): dos peticiones
        // simultáneas darían el mismo correlativo, y eso no es un bug cualquiera
        // sino un problema tributario.
        Schema::create('document_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id');
            $table->string('document_type', 30);
            $table->string('series', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            // La serie de pruebas y la real conviven, y no se pueden confundir:
            // es la barrera de DEC-029 aplicada a los correlativos.
            $table->string('environment', 15)->default('production');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['legal_entity_id', 'document_type', 'series', 'environment'], 'uq_ds_series');
            $table->index(['legal_entity_id', 'is_active'], 'ix_ds_entity');

            $table->foreign('legal_entity_id', 'fk_ds_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }
        Schema::dropIfExists('document_series');
        Schema::dropIfExists('legal_entity_countries');
        Schema::dropIfExists('legal_entities');
        Schema::dropIfExists('platform_brands');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['platform_brands', 'ck_pb_color', "primary_color IS NULL OR primary_color REGEXP '^#[0-9A-Fa-f]{6}$'", ['primary_color'], 'El color de marca debe ser hexadecimal (#RRGGBB).'],
            ['legal_entities', 'ck_le_status', "status IN ('active','inactive','dissolved')", ['status'], 'Estado de sociedad no valido.'],
            ['legal_entities', 'ck_le_dates', 'dissolved_on IS NULL OR incorporated_on IS NULL OR dissolved_on >= incorporated_on', ['dissolved_on', 'incorporated_on'], 'No puede disolverse antes de constituirse.'],
            ['legal_entities', 'ck_le_dissolved', "status <> 'dissolved' OR dissolved_on IS NOT NULL", ['status', 'dissolved_on'], 'Una sociedad disuelta debe decir cuando.'],
            ['legal_entity_countries', 'ck_lec_basis', "coverage_basis IN ('local_entity','service_export','branch','other')", ['coverage_basis'], 'Motivo de cobertura no valido.'],
            ['legal_entity_countries', 'ck_lec_dates', 'valid_to IS NULL OR valid_to >= valid_from', ['valid_to', 'valid_from'], 'La vigencia no puede terminar antes de empezar.'],
            ['document_series', 'ck_ds_type', "document_type IN ('invoice','boleta','credit_note','debit_note','other')", ['document_type'], 'Tipo de documento no valido.'],
            ['document_series', 'ck_ds_env', "environment IN ('sandbox','production')", ['environment'], 'Entorno no valido.'],
            ['document_series', 'ck_ds_number', 'next_number >= 1', ['next_number'], 'El correlativo empieza en 1.'],
        ];
    }
};
