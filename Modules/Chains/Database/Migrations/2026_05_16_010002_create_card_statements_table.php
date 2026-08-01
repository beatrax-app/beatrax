<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the card_statements table — the first-class statement lifecycle
 * ledger for ICS-kind accounts.
 *
 * Each row models one statement period: the total amount owed
 * (negative), the open balance still to settle (positive remaining),
 * and the state machine that transitions open → partially_settled →
 * settled / overpaid as ics_bulk_settle chain_links accumulate.
 *
 * Modelling the statement as a real row (rather than deriving from
 * `statement_summaries` joins on the fly) is the load-bearing data
 * design: partial settlement, overpayment carry-forward, and refund-
 * after-close behaviour all require a persistent open_balance the
 * resolver mutates atomically — boolean is_settled is structurally
 * insufficient.
 *
 * The `state` column is enforced via a BEFORE INSERT/UPDATE trigger
 * pair (same idiom Phase 1's `transactions.type` uses). The single
 * legal mutator is `Modules\Chains\Internal\CardStatementStateMachine`
 * — an architectural invariant enforced by BoundaryArchTest.
 *
 * `import_run_id` is nullable + nullOnDelete because back-populated
 * rows may outlive their original import_run; ongoing imports refresh
 * the row without losing it on import_run delete.
 *
 * UNIQUE `(user_id, account_id, period_start, period_end)` makes the
 * back-population migration's `insertOrIgnore` re-runs a no-op.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('card_statements', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->bigInteger('total_amount_minor');  // negative — outstanding
            $table->bigInteger('open_balance_minor');  // positive — remaining to settle
            $table->string('state', 24);
            $table->timestamps();

            $table->unique(['user_id', 'account_id', 'period_start', 'period_end']);
            $table->index(['user_id', 'state']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'open','partially_settled','settled','overpaid'";

        $connection->statement(sprintf(
            "CREATE TRIGGER card_statements_state_check_insert BEFORE INSERT ON card_statements FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statements.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER card_statements_state_check_update BEFORE UPDATE OF state ON card_statements FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statements.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('card_statements');
    }
};
