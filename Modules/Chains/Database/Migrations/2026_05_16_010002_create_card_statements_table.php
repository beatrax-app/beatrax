<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/** @link ../../../../.docs/features/chains/card-statement-lifecycle.md */
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
