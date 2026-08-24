<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anular un perfil fiscal aprobado (iteración 3.11, `T-15`).
 *
 * El estado del perfil sabía decir dos cosas y le faltaba una tercera:
 *
 * | estado       | significa                                        |
 * |--------------|--------------------------------------------------|
 * | `rejected`   | no pasó la revisión — nunca llegó a aprobarse     |
 * | `superseded` | dejó de aplicar y otro tomó su lugar — sí estuvo vigente |
 * | `annulled`   | **se aprobó y no debió aprobarse nunca**         |
 *
 * No lo encontró una revisión del modelo. Lo encontró
 * `test_para_un_menor_el_perfil_del_creador_no_cuenta` poniéndose en rojo contra
 * `DEC-071`: capturaba el perfil del menor y el del tutor con la misma fecha de
 * inicio, y el relevo cierra el anterior **el día antes**, cosa que no se puede
 * hacer la víspera de su propio inicio. Lo que ese caso pedía no era un relevo,
 * era anular: un perfil fiscal a nombre de un menor no fue válido ni un día.
 *
 * **Tres decisiones, todas del negocio:**
 *
 * 1. **Permiso propio `creator.tax.annul`**, no reutilizar `creator.tax.approve`.
 *    Quien aprueba a diario no debe poder borrar del histórico por descuido, y
 *    este permiso se le da a poca gente.
 * 2. **Solo se anula el vigente.** Uno ya reemplazado se queda como está:
 *    durante su ventana fue el que había en el expediente, y sobre esa ventana
 *    puede haberse liquidado dinero con esa retención. Lo impone
 *    `tg_ctp_solo_el_vigente_se_anula`, que mira `OLD` — lo único que un `CHECK`
 *    no puede hacer.
 * 3. **Al anular, el creador se queda sin perfil vigente** y deja de cumplir
 *    `BR-CREATOR-013`: no se le invita ni se le liquida hasta que se apruebe
 *    otro. Es la verdad, no un efecto secundario: no hay perfil válido.
 *
 * **Lo que NO se pone, y por qué se dice.** No hay segregación entre quien
 * aprueba y quien anula, al contrario que en `ck_ctp_segregation`. La razón es
 * que anular es *admitir un error*, y exigir una segunda persona significa que
 * quien lo cometió no puede arreglarlo — en un equipo pequeño eso bloquea más
 * de lo que protege. El control aquí es el rastro: `annulled_by_user_id`, el
 * motivo obligatorio y la bitácora, que no se reescriben. Si algún día el
 * equipo crece, esto es lo primero que hay que reconsiderar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_tax_profiles', function (Blueprint $table): void {
            $table->dateTime('annulled_at', 3)->nullable()->after('rejection_note');
            $table->unsignedBigInteger('annulled_by_user_id')->nullable()->after('annulled_at');
            $table->string('annulment_reason', 255)->nullable()->after('annulled_by_user_id');
        });

        DB::statement(
            'ALTER TABLE creator_tax_profiles '
            .'ADD CONSTRAINT fk_ctp_annuller FOREIGN KEY (annulled_by_user_id) '
            .'REFERENCES users(id) ON DELETE RESTRICT',
        );

        Restriccion::quitar('creator_tax_profiles', 'ck_ctp_status');
        Restriccion::comprobacion(
            tabla: 'creator_tax_profiles',
            nombre: 'ck_ctp_status',
            expresion: "status IN ('pending','approved','rejected','superseded','annulled')",
            columnas: ['status'],
            mensaje: 'Estado de perfil fiscal no reconocido.',
        );

        // Las dos mitades importan. Sin la segunda, un `annulled_at` suelto en
        // una fila aprobada seria una anulacion a medias que nadie sabria leer.
        Restriccion::comprobacion(
            tabla: 'creator_tax_profiles',
            nombre: 'ck_ctp_annulled',
            expresion: "(status = 'annulled' AND annulled_at IS NOT NULL AND annulled_by_user_id IS NOT NULL AND annulment_reason IS NOT NULL) "
                ."OR (status <> 'annulled' AND annulled_at IS NULL AND annulled_by_user_id IS NULL AND annulment_reason IS NULL)",
            columnas: ['status', 'annulled_at', 'annulled_by_user_id', 'annulment_reason'],
            mensaje: 'Un perfil anulado dice quien lo anulo, cuando y por que; uno que no lo esta, ninguna de las tres.',
        );

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_ctp_solo_el_vigente_se_anula`
            BEFORE UPDATE ON `creator_tax_profiles`
            FOR EACH ROW
            BEGIN
                -- Una vez anulado, la fila se congela.
                --
                -- La primera version solo miraba la ENTRADA en `annulled`
                -- (`OLD.status <> 'annulled'`), y eso dejaba reescribir el motivo de una
                -- anulacion ya hecha tantas veces como se quisiera. Lo destapo una asercion
                -- de la suite que leia el motivo y encontraba el ultimo, no el primero.
                --
                -- Anular existe justamente para no destruir el historico. Un motivo que se
                -- puede cambiar despues no es evidencia de nada.
                IF OLD.status = 'annulled' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un perfil fiscal anulado ya no se toca: el motivo y quien lo anulo son evidencia.';
                END IF;

                IF NEW.status = 'annulled'
                   AND NOT (OLD.status = 'approved' AND OLD.valid_to IS NULL) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Solo se puede anular el perfil fiscal vigente: uno ya reemplazado se queda como esta.';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_ctp_solo_el_vigente_se_anula`');
        Restriccion::quitar('creator_tax_profiles', 'ck_ctp_annulled');
        Restriccion::quitar('creator_tax_profiles', 'ck_ctp_status');
        Restriccion::comprobacion(
            tabla: 'creator_tax_profiles',
            nombre: 'ck_ctp_status',
            expresion: "status IN ('pending','approved','rejected','superseded')",
            columnas: ['status'],
            mensaje: 'Estado de perfil fiscal no reconocido.',
        );
        DB::statement('ALTER TABLE creator_tax_profiles DROP FOREIGN KEY fk_ctp_annuller');
        Schema::table('creator_tax_profiles', function (Blueprint $table): void {
            $table->dropColumn(['annulled_at', 'annulled_by_user_id', 'annulment_reason']);
        });
    }
};
