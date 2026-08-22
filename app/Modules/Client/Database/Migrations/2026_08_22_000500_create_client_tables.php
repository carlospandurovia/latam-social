<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El lado cliente.
 *
 * DEC-016 gobierna el vocabulario: nunca `Brand` a secas ni `Organization` a
 * secas. Los cuatro conceptos organizacionales no se mezclan nunca —
 * PlatformBrand (LATAM Social) · LegalEntity (quien factura) ·
 * ClientOrganization (el grupo cliente) · ClientBrand (su marca).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_organizations', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            // El nombre con el que se le conoce. La razón social vive en el
            // perfil fiscal, que es por país y puede ser distinta en cada uno.
            $table->string('commercial_name', 160);
            $table->string('client_code', 20);
            $table->foreignId('country_id');
            $table->string('website', 255)->nullable();
            $table->unsignedBigInteger('industry_category_id')->nullable();
            $table->string('status', 15)->default('prospect');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_co_uuid');
            $table->unique('client_code', 'uq_co_code');
            $table->index(['status', 'commercial_name'], 'ix_co_status');
            $table->index('country_id', 'ix_co_country');
            $table->index('owner_user_id', 'ix_co_owner');
            $table->index('industry_category_id', 'ix_co_industry');

            $table->foreign('country_id', 'fk_co_country')
                ->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('industry_category_id', 'fk_co_industry')
                ->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('owner_user_id', 'fk_co_owner')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // docs 2.2 P-02: el Grupo ABC factura desde su filial peruana y desde la
        // mexicana. Sin esto habría que crear dos clientes y partir el histórico.
        Schema::create('client_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_organization_id');
            $table->foreignId('country_id');
            $table->string('legal_name', 200);
            $table->string('tax_id_type', 20);
            $table->string('tax_id_number', 40);
            $table->string('address_line1', 180);
            $table->string('address_line2', 180)->nullable();
            $table->string('city', 100);
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('billing_email', 255)->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(30);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index('client_organization_id', 'ix_ctxp_client');
            $table->index('country_id', 'ix_ctxp_country');

            $table->foreign('client_organization_id', 'fk_ctxp_client')
                ->references('id')->on('client_organizations')->restrictOnDelete();
            $table->foreign('country_id', 'fk_ctxp_country')
                ->references('id')->on('countries')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE client_tax_profiles ADD COLUMN current_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED'
        );
        // Un solo perfil vigente por cliente y país.
        DB::statement(
            'ALTER TABLE client_tax_profiles ADD UNIQUE KEY uq_ctxp_current '
            .'(current_gate, client_organization_id, country_id)'
        );
        // Y el mismo identificador fiscal no puede estar vigente en dos clientes:
        // es lo que impide duplicar un cliente por descuido comercial, que es la
        // forma más común de partir en dos el histórico de un mismo grupo.
        DB::statement(
            'ALTER TABLE client_tax_profiles ADD UNIQUE KEY uq_ctxp_taxid '
            .'(current_gate, country_id, tax_id_type, tax_id_number)'
        );

        Schema::create('client_brands', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('client_organization_id');
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->unsignedBigInteger('logo_file_id')->nullable();
            $table->string('website', 255)->nullable();
            $table->unsignedBigInteger('brand_guidelines_file_id')->nullable();
            $table->string('status', 15)->default('active');
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_cb_uuid');
            // El slug es global porque aparece en URLs; el nombre solo es único
            // dentro del cliente, porque dos grupos pueden tener marcas homónimas.
            $table->unique('slug', 'uq_cb_slug');
            $table->unique(['client_organization_id', 'name'], 'uq_cb_name');
            $table->index(['client_organization_id', 'status'], 'ix_cb_client');
            $table->index('logo_file_id', 'ix_cb_logo');
            $table->index('brand_guidelines_file_id', 'ix_cb_guidelines');

            $table->foreign('client_organization_id', 'fk_cb_client')
                ->references('id')->on('client_organizations')->restrictOnDelete();
            $table->foreign('logo_file_id', 'fk_cb_logo')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('brand_guidelines_file_id', 'fk_cb_guidelines')
                ->references('id')->on('files')->restrictOnDelete();
        });

        Schema::create('client_brand_categories', function (Blueprint $table): void {
            $table->foreignId('client_brand_id');
            $table->foreignId('category_id');
            $table->dateTime('created_at', 3)->nullable();

            $table->primary(['client_brand_id', 'category_id']);
            $table->index('category_id', 'ix_cbc_category');

            $table->foreign('client_brand_id', 'fk_cbc_brand')
                ->references('id')->on('client_brands')->restrictOnDelete();
            $table->foreign('category_id', 'fk_cbc_category')
                ->references('id')->on('categories')->restrictOnDelete();
        });

        // docs 2.3 N-01: Contact = una PERSONA. User = unas CREDENCIALES.
        // No existe ClientUser; un usuario del cliente es Contact + User enlazados.
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('client_organization_id');
            // Nulo mientras la persona no necesite entrar al sistema, que es la
            // mayoría de los casos.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('full_name', 160);
            // NO es lo mismo que users.email. Este es el canal comercial y puede
            // ser compartido ("facturacion@cliente.com"); aquel es la identidad
            // de acceso y es única. Los dos hechos son ciertos a la vez.
            $table->string('contact_email', 255);
            $table->string('phone', 30)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('contact_type', 15)->default('commercial');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 15)->default('active');
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_contacts_uuid');
            $table->unique('user_id', 'uq_contacts_user');
            $table->index(['client_organization_id', 'status'], 'ix_contacts_client');
            $table->index('contact_email', 'ix_contacts_email');

            $table->foreign('client_organization_id', 'fk_contacts_client')
                ->references('id')->on('client_organizations')->restrictOnDelete();
            $table->foreign('user_id', 'fk_contacts_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Un solo contacto principal por cliente y tipo: a quién se le escribe.
        // Incluye `status` en la puerta para que desactivar al principal libere
        // el puesto sin tener que acordarse de bajar la marca antes.
        DB::statement(
            'ALTER TABLE contacts ADD COLUMN primary_gate TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN is_primary = 1 AND status = 'active' THEN 1 ELSE NULL END) STORED"
        );
        DB::statement(
            'ALTER TABLE contacts ADD UNIQUE KEY uq_contacts_primary '
            .'(primary_gate, client_organization_id, contact_type)'
        );

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
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('client_brand_categories');
        Schema::dropIfExists('client_brands');
        Schema::dropIfExists('client_tax_profiles');
        Schema::dropIfExists('client_organizations');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['client_organizations', 'ck_co_status', "status IN ('prospect','active','inactive','blacklisted')", ['status'], 'Estado de cliente no valido.'],
            ['client_tax_profiles', 'ck_ctxp_dates', 'valid_to IS NULL OR valid_to >= valid_from', ['valid_to', 'valid_from'], 'La vigencia no puede terminar antes de empezar.'],
            ['client_tax_profiles', 'ck_ctxp_term', 'payment_term_days BETWEEN 0 AND 180', ['payment_term_days'], 'El plazo de pago debe estar entre 0 y 180 dias.'],
            ['client_brands', 'ck_cb_status', "status IN ('active','paused','archived')", ['status'], 'Estado de marca no valido.'],
            ['contacts', 'ck_contacts_type', "contact_type IN ('commercial','billing','legal','operations')", ['contact_type'], 'Tipo de contacto no valido.'],
            ['contacts', 'ck_contacts_status', "status IN ('active','inactive')", ['status'], 'Estado de contacto no valido.'],
        ];
    }
};
