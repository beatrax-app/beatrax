<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// SQLite cannot ALTER TABLE ADD CHECK after the fact, so the enum-shaped
// string columns (kind, state) are constrained by BEFORE INSERT /
// BEFORE UPDATE trigger pairs instead.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('chain_links', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_transaction_id')->constrained('transactions')->cascadeOnDelete();
            // Nullable only for exceeded-tolerance ics_bulk_settle candidates.
            $table->foreignId('to_transaction_id')->nullable()->constrained('transactions')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('state', 16);
            $table->decimal('confidence', 4, 3); // 0.000..1.000
            $table->string('resolver', 8);        // 'auto' | 'user' | 'rule'
            $table->json('evidence');
            $table->timestamps();

            $table->index('from_transaction_id');
            $table->index('to_transaction_id');
            $table->index(['user_id', 'state']); // review-queue scan
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedKinds = "'paypal_funding','ics_bulk_settle'";
        $allowedStates = "'candidate','confirmed','rejected'";

        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_kind_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
            $allowedKinds,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_kind_check_update BEFORE UPDATE OF kind ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
            $allowedKinds,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_state_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_state_check_update BEFORE UPDATE OF state ON chain_links FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.state value'); END",
            $allowedStates,
        ));

        // No single ICS expense maps to the bulk settlement once the tolerance
        // window is breached, so the resolver records the open statement on
        // evidence and leaves the endpoint NULL. Nothing else may.
        $nullGuard = 'NEW.to_transaction_id IS NULL'
            .' AND NOT ('
                ."NEW.state = 'candidate'"
                ." AND NEW.kind = 'ics_bulk_settle'"
                ." AND json_extract(NEW.evidence, '$.tolerance_used') = 'exceeded'"
            .')';

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

    public function down(): void
    {
        $this->schema()->dropIfExists('chain_links');
    }
};
