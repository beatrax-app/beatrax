<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

// SQLite cannot ALTER a trigger in place, so both pairs are dropped and
// recreated. The hint kinds ride with to_transaction_id NULL because the
// funder transaction may not exist yet; down() restores the original
// allow-list and guard exactly.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

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
};
