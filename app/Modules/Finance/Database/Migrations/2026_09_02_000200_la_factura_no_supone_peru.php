<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La factura congela su localidad y deja de suponer Perú (9.9f, `T-87` y `T-79`).
 *
 * Las dos deudas venían de `9.9b` y las dejó a la vista `9.9d`, que fue la
 * primera iteración que **leyó** la factura para construir un documento oficial.
 *
 * ### `T-87` — la localidad no estaba congelada
 *
 * `BR-LE-005` congela el nombre, el identificador fiscal y el domicilio del
 * emisor: la sociedad cambia de domicilio y la factura de ayer no. Pero el
 * **ubigeo, el distrito, la provincia y el código de establecimiento** no se
 * congelaron, y son exactamente los campos que el comprobante electrónico lleva
 * dentro. `9.9d` los estaba leyendo de la tabla viva.
 *
 * Consecuencia concreta: el día que una sociedad se mude, regenerar el XML de
 * una factura del año pasado produciría **un documento distinto del que se
 * emitió**. Y como el XML va firmado, «distinto» significa que las dos firmas
 * no pueden ser las dos válidas para lo mismo.
 *
 * ### `T-79` — los cuatro tipos peruanos vivían dentro de un CHECK
 *
 * `ck_invoice_type` decía `document_type IN ('invoice','boleta','credit_note',
 * 'debit_note')`. Es la lista de Perú escrita en el esquema, y `DEC-190` dice
 * que las reglas van en el código y **los valores en la configuración**. Una
 * factura electrónica colombiana o una nota de crédito mexicana no caben ahí sin
 * una migración.
 *
 * El catálogo ya existe desde la Fase 2 —`document_types`, por país— y `9.9d` ya
 * lee de él el código oficial que viaja en el XML. Lo que faltaba era que la
 * regla mirase ahí en vez de a una lista suya.
 *
 * **Se convierte en disparador y no en clave ajena** por lo mismo que
 * `9.17e` convirtió `ck_iconn_url`: la pregunta es CRUZADA —«¿existe este tipo
 * en el país de ESTE emisor?»— y una foránea a `document_types(id)` obligaría a
 * cambiar `uq_invoice_number`, que es la unicidad que SUNAT exige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $tabla): void {
            // Los cuatro que el comprobante lleva dentro y que `9.9b` no copio.
            $tabla->string('issuer_tax_location_snapshot', 12)->nullable()
                ->after('issuer_country_snapshot');
            $tabla->string('issuer_district_snapshot', 100)->nullable()
                ->after('issuer_tax_location_snapshot');
            $tabla->string('issuer_province_snapshot', 100)->nullable()
                ->after('issuer_district_snapshot');
            $tabla->string('issuer_region_snapshot', 100)->nullable()
                ->after('issuer_province_snapshot');
            $tabla->string('issuer_establishment_snapshot', 10)->nullable()
                ->after('issuer_region_snapshot');
        });

        $this->copiarLoQueYaSeEmitio();

        // T-79: fuera la lista peruana, dentro la regla cruzada.
        Restriccion::quitar('invoices', 'ck_invoice_type');

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    /**
     * Lo ya emitido se rellena con lo que hoy dice la sociedad.
     *
     * **Es una aproximación, y hay que decirlo.** Si alguna sociedad se mudó
     * entre la emisión y hoy, esta copia guarda el domicilio de HOY, no el de
     * entonces — que es justo el defecto que esta migración viene a impedir
     * hacia delante. No hay forma de recuperar el dato de entonces: no se
     * guardó. Lo que sí se consigue es que **a partir de ahora deje de
     * cambiar**, y eso es lo que se podía arreglar.
     *
     * Los borradores no se tocan: congelan al emitir, no antes.
     */
    private function copiarLoQueYaSeEmitio(): void
    {
        DB::statement(<<<'SQL'
            UPDATE invoices i
              JOIN legal_entities le ON le.id = i.legal_entity_id
               SET i.issuer_tax_location_snapshot   = le.tax_location_code,
                   i.issuer_district_snapshot       = le.district,
                   i.issuer_province_snapshot       = le.city,
                   i.issuer_region_snapshot         = le.region,
                   i.issuer_establishment_snapshot  = le.establishment_code,
                   i.updated_at                     = i.updated_at
             WHERE i.status <> 'draft'
               AND i.issuer_tax_location_snapshot IS NULL
        SQL);
    }

    /**
     * El tipo tiene que existir en el catálogo del país del emisor.
     *
     * Se comprueba **sólo cuando hay país congelado**: las facturas anteriores a
     * `9.9b` no lo tienen, y rechazar un `UPDATE` sobre ellas convertiría una
     * regla nueva en un bloqueo para arreglar datos viejos.
     *
     * Y **sólo al emitir**: un borrador todavía no ha elegido serie, así que su
     * `document_type` es el valor por defecto y no significa nada.
     *
     * @return array<string, string>
     */
    private static function disparadores(): array
    {
        $cuerpo = <<<'SQL'
          FOR EACH ROW
          BEGIN
            DECLARE v_existe INT DEFAULT 0;

            IF NEW.status <> 'draft' AND NEW.issuer_country_snapshot IS NOT NULL THEN
              SELECT COUNT(*) INTO v_existe
                FROM document_types dt
                JOIN countries c ON c.id = dt.country_id
               WHERE c.iso2 = NEW.issuer_country_snapshot
                 AND dt.code = NEW.document_type;

              IF v_existe = 0 THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Ese tipo de comprobante no existe en el catalogo del pais del emisor.';
              END IF;
            END IF;
          END
        SQL;

        return [
            'tg_invoice_tipo_ins' => 'BEFORE INSERT ON `invoices` '.$cuerpo,
            'tg_invoice_tipo_upd' => 'BEFORE UPDATE ON `invoices` '.$cuerpo,
        ];
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_invoice_tipo_ins`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_invoice_tipo_upd`');

        Schema::table('invoices', function (Blueprint $tabla): void {
            $tabla->dropColumn([
                'issuer_tax_location_snapshot', 'issuer_district_snapshot',
                'issuer_province_snapshot', 'issuer_region_snapshot',
                'issuer_establishment_snapshot',
            ]);
        });
    }
};
