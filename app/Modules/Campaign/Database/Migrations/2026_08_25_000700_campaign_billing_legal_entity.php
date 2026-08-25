<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La campaña dice qué sociedad la factura, y deja de poder cambiarlo (7.1).
 *
 * ### El hueco
 *
 * `BR-LE-001` es 🔴 y nombra la campaña **explícitamente**:
 *
 * > Todo documento comercial o fiscal (propuesta, contrato, **campaña**,
 * > factura, nota, pago, liquidación) almacena explícitamente su
 * > `legal_entity_id`. **Nunca se deduce de la configuración vigente en el
 * > momento de la consulta.**
 *
 * `campaigns` se creó en la Fase 2 sin esa columna. La regla existía, el
 * esquema no la cumplía, y como no había ninguna pantalla de campañas nadie lo
 * había notado: es el mismo patrón que `must_change_password`, escrita desde 3.1
 * y sin nadie que la leyera hasta `T-23`.
 *
 * Lo que se pierde sin la columna no es cosmético. Dentro de dos años, mirando
 * una campaña de 2026, «¿quién la facturó?» se respondería consultando la
 * cobertura **de hoy**, que para entonces puede ser otra sociedad. La respuesta
 * sería plausible y falsa, que es la peor clase de respuesta.
 *
 * ### Cuándo se resuelve: `starts_on` (decisión de negocio, 2026-08-25)
 *
 * `BR-LE-003` dice «en la fecha de la operación». Para una campaña esa fecha es
 * **cuándo empieza a prestarse el servicio**, no cuándo se teclea ni cuándo se
 * firma: es lo que un contador defiende ante SUNAT.
 *
 * Consecuencia concreta: una campaña creada en diciembre para arrancar en
 * febrero se resuelve con la cobertura de **febrero**. Si la cobertura cambia el
 * 1 de enero, la campaña ya nació con la sociedad correcta en vez de con una que
 * habría que corregir después — y corregirla es justo lo que la inmutabilidad
 * impide.
 *
 * ### Cuándo se congela: al confirmar (decisión de negocio, 2026-08-25)
 *
 * `BR-LE-002` dice «inmutable una vez emitido». Para una campaña, «emitido» es
 * ambiguo, así que se decidió: mientras está en `draft` o `pending_approval` se
 * puede corregir un dedazo; **en cuanto tiene `confirmed_at`, no se toca**.
 *
 * La alternativa —congelarla al salir de `draft`— es más estricta y peor: un
 * error de captura obligaría a cancelar la campaña y rehacerla, y el histórico
 * se llenaría de campañas `cancelled` que no se cancelaron por negocio. Un
 * estado que miente sobre por qué existe es peor que un campo editable un rato
 * más.
 *
 * ### Por qué la columna es NULL-able y aun así obligatoria
 *
 * Un borrador puede no tener sociedad todavía: se está tecleando. Lo que no
 * puede es pasar de ahí. `ck_camp_billing_entity` lo impone con la misma forma
 * que `ck_camp_confirmed`, que ya estaba: *o estás en un estado inicial, o el
 * dato existe*.
 *
 * Poner la columna `NOT NULL` habría obligado a inventar una sociedad por
 * defecto en el alta, y `BR-LE-004` dice exactamente lo contrario: **nunca se
 * asigna una entidad por defecto ni se continúa en silencio**.
 */
return new class extends Migration
{
    /** Estados en los que la campaña todavia se esta escribiendo. */
    private const BORRADOR = "'draft', 'pending_approval', 'cancelled'";

    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedBigInteger('billing_legal_entity_id')->nullable()->after('client_brand_id');
            $table->index('billing_legal_entity_id', 'ix_camp_legal_entity');
            $table->foreign('billing_legal_entity_id', 'fk_camp_legal_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'campaigns',
            nombre: 'ck_camp_billing_entity',
            expresion: 'status IN ('.self::BORRADOR.') OR billing_legal_entity_id IS NOT NULL',
            columnas: ['status', 'billing_legal_entity_id'],
            mensaje: 'Una campana que sale de borrador tiene que decir que sociedad la factura.',
        );

        DB::unprepared(self::disparador());
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_camp_entidad_congelada`');
        Restriccion::quitar('campaigns', 'ck_camp_billing_entity');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign('fk_camp_legal_entity');
            $table->dropIndex('ix_camp_legal_entity');
            $table->dropColumn('billing_legal_entity_id');
        });
    }

    /**
     * El congelado, y por que es un disparador y no una comprobacion de la
     * aplicacion.
     *
     * Un `CHECK` no puede mirar el valor ANTERIOR de una fila: solo ve la nueva.
     * «No cambiar esto» es una regla sobre la transicion, no sobre el estado, y
     * eso en MySQL solo lo expresa un disparador.
     *
     * Y no vale dejarlo en el controlador. `BR-LE-002` protege el dato del que
     * cuelga que una factura de hace dos anos siga sabiendo quien la emitio: una
     * regla asi tiene que sobrevivir a un `UPDATE` de mantenimiento, a una
     * importacion y a la proxima pantalla que alguien escriba sin acordarse.
     * Mismo criterio que `tg_cpm_inmutable` con la cuenta bancaria.
     *
     * `<=>` y no `<>`: con `<>`, pasar de una sociedad a NULL --o al reves-- da
     * NULL, que no es cierto, y el disparador dejaria pasar justo el caso de
     * borrar el dato.
     */
    private static function disparador(): string
    {
        return <<<'SQL'
            CREATE TRIGGER `tg_camp_entidad_congelada` BEFORE UPDATE ON `campaigns`
            FOR EACH ROW
            BEGIN
              IF OLD.confirmed_at IS NOT NULL
                 AND NOT (NEW.billing_legal_entity_id <=> OLD.billing_legal_entity_id)
              THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'La sociedad que factura una campana confirmada no se cambia (BR-LE-002): anule la campana y cree otra.';
              END IF;
            END
            SQL;
    }
};
