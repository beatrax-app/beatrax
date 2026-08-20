<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/** @link ../../../../.docs/features/chains/card-statement-lifecycle.md */
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
