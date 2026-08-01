<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Extends the `chain_links.kind` enum trigger pair to allow the two
 * receipt-derived "hint" kinds emitted by the Phase 7 Wave 2
 * `CreateChainLinkFromHint` listener:
 *
 *   - `funded_by_card_hint` — written when a receipt matcher
 *     surfaces a card-last-four anchor (e.g. an ICS receipt
 *     containing "eindigend op 1234"). The candidate row carries
 *     `to_transaction_id = NULL` because the matching ICS card
 *     statement transaction may not yet exist — a future Chains
 *     resolver promotes the row to `confirmed` once the funder
 *     surfaces.
 *
 *   - `refund_of_hint` — written when a receipt matcher extracts
 *     the original-order reference id from a refund body. Same
 *     candidate-state shape; `to_transaction_id` is NULL until a
 *     Chains resolver pairs the refund leg back to its original
 *     transaction via the reference id lookup.
 *
 * The migration also extends the `to_transaction_id` NULL guard so
 * the two new hint kinds are permitted to carry a NULL endpoint while
 * in `state = 'candidate'`. The existing exceeded-tolerance
 * `ics_bulk_settle` carve-out is preserved verbatim. Every other
 * combination (confirmed/rejected with NULL endpoint, or any
 * non-candidate hint row with NULL endpoint) still fails the trigger.
 *
 * SQLite cannot ALTER a trigger in place, so the migration DROPs the
 * existing trigger pairs and recreates them with the extended
 * allow-list. The down() path restores the original triggers exactly.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // Extended kind allow-list — appends the two hint variants.
        $allowedKindsExtended = "'paypal_funding','ics_bulk_settle','funded_by_card_hint','refund_of_hint'";

        $connection->statement('DROP TRIGGER IF EXISTS chain_links_kind_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS chain_links_kind_check_update');

        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_kind_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
            $allowedKindsExtended,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_kind_check_update BEFORE UPDATE OF kind ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
            $allowedKindsExtended,
        ));

        // Extended NULL-endpoint guard — permits the two hint kinds to
        // ride with `to_transaction_id IS NULL` while in candidate
        // state. The existing exceeded-tolerance ics_bulk_settle
        // carve-out is preserved verbatim.
        $nullGuard = 'NEW.to_transaction_id IS NULL'
            .' AND NOT ('
                ."NEW.state = 'candidate'"
                ." AND NEW.kind = 'ics_bulk_settle'"
                ." AND json_extract(NEW.evidence, '$.tolerance_used') = 'exceeded'"
            .')'
            .' AND NOT ('
                ."NEW.state = 'candidate'"
                ." AND NEW.kind IN ('funded_by_card_hint','refund_of_hint')"
            .')';

        $connection->statement('DROP TRIGGER IF EXISTS chain_links_to_transaction_id_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS chain_links_to_transaction_id_check_update');

        $connection->statement(
            'CREATE TRIGGER chain_links_to_transaction_id_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN '.$nullGuard."
             BEGIN SELECT RAISE(ABORT, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates or candidate hint rows'); END"
        );
        $connection->statement(
            'CREATE TRIGGER chain_links_to_transaction_id_check_update BEFORE UPDATE ON chain_links FOR EACH ROW
             WHEN '.$nullGuard."
             BEGIN SELECT RAISE(ABORT, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates or candidate hint rows'); END"
        );
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // Restore the original allow-list + guard verbatim.
        $allowedKindsOriginal = "'paypal_funding','ics_bulk_settle'";

        $connection->statement('DROP TRIGGER IF EXISTS chain_links_kind_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS chain_links_kind_check_update');

        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_kind_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
            $allowedKindsOriginal,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_kind_check_update BEFORE UPDATE OF kind ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
            $allowedKindsOriginal,
        ));

        $nullGuard = 'NEW.to_transaction_id IS NULL'
            .' AND NOT ('
                ."NEW.state = 'candidate'"
                ." AND NEW.kind = 'ics_bulk_settle'"
                ." AND json_extract(NEW.evidence, '$.tolerance_used') = 'exceeded'"
            .')';

        $connection->statement('DROP TRIGGER IF EXISTS chain_links_to_transaction_id_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS chain_links_to_transaction_id_check_update');

        $connection->statement(
            'CREATE TRIGGER chain_links_to_transaction_id_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN '.$nullGuard."
             BEGIN SELECT RAISE(ABORT, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates'); END"
        );
        $connection->statement(
            'CREATE TRIGGER chain_links_to_transaction_id_check_update BEFORE UPDATE ON chain_links FOR EACH ROW
             WHEN '.$nullGuard."
             BEGIN SELECT RAISE(ABORT, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates'); END"
        );
    }

    /**
     * Lazy DatabaseManager resolution via the container — keeps the
     * migration consistent with the parent migration's DI pattern and
     * avoids the DB facade.
     */
};
