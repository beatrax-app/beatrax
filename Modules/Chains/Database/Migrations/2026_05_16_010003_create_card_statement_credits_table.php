<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the card_statement_credits table — the carry-forward credit
 * ledger that captures overpayment surplus and refund-after-close
 * amounts.
 *
 * When a card_statement goes overpaid by €1.53, the next-period
 * statement gets a virtual `credit_carry` row applied to its
 * `open_balance_minor` at creation time. The credit lives here as a
 * (from_statement_id, to_statement_id, amount_minor) tuple so the
 * audit trail is intact and the chain drill-down drawer can surface
 * the carry as a "↩ €1.53 credit carried from Feb statement" line on
 * the receiving statement's settlement node.
 *
 * `to_statement_id` is nullable + nullOnDelete because an
 * overpayment surplus may exist BEFORE the next statement period
 * rolls in. When the next statement lands, a follow-up resolver pass
 * updates `to_statement_id` to point at it. `from_statement_id`
 * cascadeOnDelete because a credit row that lost its source statement
 * has no remaining meaning.
 *
 * The `reason` column is enforced via a BEFORE INSERT/UPDATE trigger
 * pair (same idiom as the other enum-shaped string columns in this
 * wave).
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('card_statement_credits', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_statement_id')->constrained('card_statements')->cascadeOnDelete();
            $table->foreignId('to_statement_id')->nullable()->constrained('card_statements')->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('reason', 32);
            $table->timestamps();

            $table->index(['user_id', 'to_statement_id']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedReasons = "'overpayment','refund_after_close'";

        $connection->statement(sprintf(
            "CREATE TRIGGER card_statement_credits_reason_check_insert BEFORE INSERT ON card_statement_credits FOR EACH ROW
             WHEN NEW.reason NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statement_credits.reason value'); END",
            $allowedReasons,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER card_statement_credits_reason_check_update BEFORE UPDATE OF reason ON card_statement_credits FOR EACH ROW
             WHEN NEW.reason NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statement_credits.reason value'); END",
            $allowedReasons,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('card_statement_credits');
    }
};
