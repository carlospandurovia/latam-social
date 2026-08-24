<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo que es evidencia no se borra (iteración 3.12, `T-16`).
 *
 * Nueve tablas ya estaban protegidas —`audit_logs`, `invoices`,
 * `ledger_entries`, `payouts`, `payments`, `invoice_lines`, `campaign_costs`,
 * `creator_payment_methods` y `social_account_snapshots`— y otras nueve
 * guardaban evidencia igual de definitiva sin ninguna protección.
 *
 * **De dónde salió.** De escribir la suite de 3.11. La aserción que iba a
 * escribir allí decía «el `DELETE` funciona» — o sea, habría fijado el hueco
 * como si fuera lo correcto, que es exactamente el error que `PerfilFiscalTest`
 * cometió con `T-12`. Anular un perfil fiscal existe para **no** destruir el
 * histórico, y un `DELETE` se lo llevaba entero, motivo y autor incluidos.
 *
 * **El criterio, que es uno solo:** la fila es *evidencia de algo que pasó*, y
 * de ella depende dinero o una obligación legal. Los catálogos, las tablas de
 * unión y los datos operativos se siguen pudiendo borrar — `creator_blackouts`
 * es el ejemplo: un bloqueo de agenda apuntado por error se borra y no pasa
 * nada, porque no es evidencia de nada.
 *
 * **Lo que se dejó fuera a propósito**, porque su módulo todavía no existe y
 * decidirlo ahora sería adivinar: `campaign_creators` (lleva `agreed_amount`, el
 * precio pactado), `agreement_amendments`, `domain_events` y
 * `status_transitions`. Que lo decida la iteración que los construya, con el
 * caso de uso delante. Queda como `Q-50`.
 */
return new class extends Migration
{
    private const PROTEGER = [
        'ctp' => ['creator_tax_profiles', 'de aqui sale la retencion que se le practico al creador'],
        'ctd' => ['creator_tax_documents', 'son los documentos que respaldan un pago'],
        'ctxp' => ['client_tax_profiles', 'de aqui salen el RUC y la razon social de la factura'],
        'tacc' => ['terms_acceptances', 'es la prueba de que el creador acepto; sin ella no hay contrato'],
        'tver' => ['terms_versions', 'es el texto que se acepto; sin el, la aceptacion no dice nada'],
        'cguard' => ['creator_guardians', 'es la autorizacion del tutor de un menor, un documento legal'],
        'fx' => ['exchange_rates', 'es el cambio con el que se convirtio dinero en una fecha'],
        'lec' => ['legal_entity_countries', 'dice que sociedad facturo cada pais y desde cuando'],
        'pev' => ['publication_evidence', 'es la prueba de que se publico, y de ella depende que se pague'],
    ];

    public function up(): void
    {
        foreach (self::PROTEGER as $prefijo => [$tabla, $razon]) {
            DB::statement("DROP TRIGGER IF EXISTS `tg_{$prefijo}_no_delete`");
            DB::unprepared(<<<SQL
                CREATE TRIGGER `tg_{$prefijo}_no_delete`
                BEFORE DELETE ON `{$tabla}`
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = '{$tabla} no admite borrado: {$razon}.';
                END
                SQL);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PROTEGER) as $prefijo) {
            DB::statement("DROP TRIGGER IF EXISTS `tg_{$prefijo}_no_delete`");
        }
    }
};
