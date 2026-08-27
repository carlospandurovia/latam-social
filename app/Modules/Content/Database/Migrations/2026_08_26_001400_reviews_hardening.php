<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La revisión empieza a existir (8.3).
 *
 * ### El contador de rondas estaba en la tabla equivocada
 *
 * `campaign_creators.revision_rounds_used` decía «2 rondas por CREADOR». Con un
 * creador que entrega dos reels y tres stories, dos correcciones sobre el primer
 * reel dejaban las otras cuatro piezas **sin ninguna ronda** — y el cliente había
 * pagado dos por cada una.
 *
 * Decisión de negocio (2026-08-26): las rondas son **por entregable**. El contador
 * baja a `deliverables`, que es donde se aplica la regla.
 *
 * Y la columna de `campaign_creators` **se va**, no se queda como suma. Una suma
 * almacenada que nadie recalcula es un número que se desvía en silencio, y el
 * dato que alimenta el Creator Score —«cuántas vueltas cuesta trabajar con esta
 * persona»— sale de `content_reviews` con un `SUM()` que nunca miente. Hoy tiene
 * cero filas, así que quitarla es gratis; dentro de tres meses no lo sería.
 *
 * ### Quién pide la corrección decide si consume ronda
 *
 * Sólo las rondas del **cliente** cuentan contra el precio (`BR-CONTENT-003`).
 * Que nuestro equipo le pida al creador rehacer el encuadre antes de enseñárselo
 * a nadie es control de calidad nuestro, y cobrárselo al cliente sería cobrarle
 * nuestro propio error.
 *
 * El portal del cliente es `8.5`, así que hoy el comentario del cliente lo
 * traslada quien lleva la cuenta y marca la revisión como suya — que es
 * exactamente como trabaja una agencia antes de tener portal. En `8.5` el enlace
 * firmado escribe la misma fila sin intermediario.
 *
 * ### El cargo adicional NO va a `campaign_costs`
 *
 * Tentador y equivocado. `campaign_costs` es lo que **nosotros** gastamos y
 * resta del margen (`BR-FIN-011`); una ronda de más facturada al cliente es
 * ingreso, no costo. Meterla ahí bajaría el margen de una campaña que acaba de
 * ganar dinero.
 *
 * Aquí se registra **la decisión** —se cobra o se absorbe, quién la tomó y por
 * qué— y la pantalla la enseña como pendiente de facturar. Dónde acaba la línea
 * cuando exista facturación (F9) es `Q-57`, y se decide con `invoice_lines`
 * delante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverables', function (Blueprint $table): void {
            // Las rondas del CLIENTE gastadas en esta pieza. Las internas no
            // cuentan aqui: no se le cobran a nadie.
            $table->unsignedTinyInteger('revision_rounds_used')->default(0)->after('status');
            // Quien dio el visto bueno. `approved_at` ya decia CUANDO y no QUIEN,
            // y de esta firma cuelga que el contenido salga hacia el cliente.
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at');

            $table->index('approved_by_user_id', 'ix_del_aprobador');
            $table->foreign('approved_by_user_id', 'fk_del_aprobador')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('content_reviews', function (Blueprint $table): void {
            // Esta ronda va POR ENCIMA de las incluidas en el precio.
            $table->boolean('over_included')->default(false)->after('consumes_round');
            // `charge` o `absorb`. Obligatoria cuando `over_included`, y prohibida
            // cuando no: una decision de facturacion sobre una ronda que estaba
            // incluida es ruido que alguien acabara leyendo como un cargo.
            $table->string('billing_decision', 10)->nullable()->after('over_included');
            $table->unsignedBigInteger('authorized_by_user_id')->nullable()->after('billing_decision');
            $table->binary('reviewed_ip', 16)->nullable()->after('reviewed_at');
            // La tabla es append-only y NO lleva `updated_at` a proposito (2.12).
            // `created_at` si, porque `reviewed_at` es el momento del veredicto y
            // puede venir de fuera; este es el momento en que entro la fila.
            $table->dateTime('created_at', 3)->nullable();

            $table->index('authorized_by_user_id', 'ix_cvw_autorizador');
            $table->foreign('authorized_by_user_id', 'fk_cvw_autorizador')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        foreach (self::disparadores() as $sql) {
            DB::unprepared($sql);
        }

        // Se quita LA ULTIMA: si algo de lo de arriba falla, la columna sigue
        // ahi y la vuelta atras es trivial.
        Schema::table('campaign_creators', function (Blueprint $table): void {
            $table->dropColumn('revision_rounds_used');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_creators', function (Blueprint $table): void {
            $table->unsignedTinyInteger('revision_rounds_used')->default(0)->after('payment_term_days_snapshot');
        });

        DB::statement('DROP TRIGGER IF EXISTS `tg_cvw_inmutable`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_cvw_ultima_version`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_cvw_entregable_abierto`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('content_reviews', function (Blueprint $table): void {
            $table->dropForeign('fk_cvw_autorizador');
            $table->dropIndex('ix_cvw_autorizador');
            $table->dropColumn([
                'over_included', 'billing_decision', 'authorized_by_user_id',
                'reviewed_ip', 'created_at',
            ]);
        });

        Schema::table('deliverables', function (Blueprint $table): void {
            $table->dropForeign('fk_del_aprobador');
            $table->dropIndex('ix_del_aprobador');
            $table->dropColumn(['revision_rounds_used', 'approved_by_user_id']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Pedir cambios exige DECIR CUALES. Una correccion sin texto le
            // llega al creador como «hazlo otra vez» y garantiza una vuelta mas
            // --justo lo que las rondas cuentan--. Diez caracteres no es un
            // filtro de calidad; es el minimo para que no sea un punto.
            ['content_reviews', 'ck_cvw_comments',
                "outcome <> 'changes_requested' OR CHAR_LENGTH(TRIM(COALESCE(comments,''))) >= 10",
                ['outcome', 'comments'],
                'Para pedir cambios hay que decir cuales: el comentario es obligatorio.'],

            // Una ronda por encima de lo incluido exige DECIDIR y FIRMAR. Sin
            // esto, «se paso de rondas» es una nota que nadie factura.
            ['content_reviews', 'ck_cvw_over',
                'over_included = 0 OR (billing_decision IS NOT NULL AND authorized_by_user_id IS NOT NULL)',
                ['over_included', 'billing_decision', 'authorized_by_user_id'],
                'Una ronda por encima de lo incluido exige decidir si se cobra y quien lo autoriza.'],

            // Y al reves: sin exceso no hay decision que tomar.
            ['content_reviews', 'ck_cvw_billing',
                'over_included = 1 OR (billing_decision IS NULL AND authorized_by_user_id IS NULL)',
                ['over_included', 'billing_decision', 'authorized_by_user_id'],
                'Solo una ronda por encima de lo incluido lleva decision de facturacion.'],

            ['content_reviews', 'ck_cvw_billing_valor',
                "billing_decision IS NULL OR billing_decision IN ('charge','absorb')",
                ['billing_decision'],
                'La decision de facturacion solo puede ser cobrar o absorber.'],

            // Lo que se pasa de lo incluido es siempre una ronda del cliente:
            // las internas no cuentan contra el precio, asi que no pueden
            // pasarse de el.
            ['content_reviews', 'ck_cvw_over_es_ronda',
                'over_included = 0 OR consumes_round = 1',
                ['over_included', 'consumes_round'],
                'Solo una revision que consume ronda puede pasarse de las incluidas.'],

            // Una revision NUESTRA la firma alguien. La del cliente puede no
            // tener usuario --en 8.5 la escribe un enlace firmado, sin cuenta--.
            ['content_reviews', 'ck_cvw_firma',
                "reviewer_side <> 'platform' OR reviewer_user_id IS NOT NULL",
                ['reviewer_side', 'reviewer_user_id'],
                'Una revision interna la firma quien la hace.'],

            // Aprobado dice CUANDO desde 2.12; ahora dice tambien QUIEN.
            ['deliverables', 'ck_del_aprobador',
                'approved_at IS NULL OR approved_by_user_id IS NOT NULL',
                ['approved_at', 'approved_by_user_id'],
                'Un entregable aprobado tiene que decir quien lo aprobo.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Los dos caben en 128 caracteres a proposito: MySQL y Percona cortan
        // `MESSAGE_TEXT` ahi y sueltan `1648` en vez del `45000`. MariaDB lo
        // perdona, o sea que el fallo solo se ve en produccion. Lo vigila
        // `tools/verificar-mensajes.py`.
        $vieja = 'No se revisa una version que ya no es la ultima: el creador mando otra. '
            .'Revise la mas reciente.';

        $cerrado = 'Ese entregable ya esta aprobado o cancelado y no admite mas veredictos. '
            .'Un veredicto no se edita.';

        $inmutable = 'Un veredicto no se edita: se emite otro. content_reviews solo admite insercion.';

        return [
            // CROSS-TABLE las dos: miran `deliverable_versions` y `deliverables`,
            // asi que un CHECK no sirve --solo ve su propia fila--.
            <<<SQL
                CREATE TRIGGER `tg_cvw_ultima_version` BEFORE INSERT ON `content_reviews`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverable_versions` v
                              WHERE v.`deliverable_id` = (SELECT `deliverable_id`
                                                            FROM `deliverable_versions`
                                                           WHERE `id` = NEW.`deliverable_version_id`)
                                AND v.`version_number` > (SELECT `version_number`
                                                            FROM `deliverable_versions`
                                                           WHERE `id` = NEW.`deliverable_version_id`))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$vieja}';
                  END IF;
                END
                SQL,
            <<<SQL
                CREATE TRIGGER `tg_cvw_entregable_abierto` BEFORE INSERT ON `content_reviews`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverable_versions` v
                               JOIN `deliverables` d ON d.`id` = v.`deliverable_id`
                              WHERE v.`id` = NEW.`deliverable_version_id`
                                AND d.`status` IN ('approved','published','verified','cancelled'))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$cerrado}';
                  END IF;
                END
                SQL,
            // «Append-only: un veredicto no se edita, se emite otro» lo dice el
            // documento de 2.12 desde el primer dia, y no lo impedia nada. Un
            // veredicto justifica una ronda cobrada; si se puede reescribir,
            // reconstruir por que se facturo algo depende de que nadie lo tocara.
            //
            // Se bloquea el UPDATE ENTERO y no columna por columna: no hay
            // ninguna columna de esta tabla que tenga sentido cambiar despues.
            <<<SQL
                CREATE TRIGGER `tg_cvw_inmutable` BEFORE UPDATE ON `content_reviews`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$inmutable}';
                END
                SQL,
        ];
    }
};
