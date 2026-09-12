<?php

declare(strict_types=1);

use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Core\Database\Support\ModuleMigration;

// Promotion took abs() of the closing balance, so a card paid off past zero was
// written owing the credit it holds. The ICS reader restores the Af/Bij marker
// to that figure, and the two writers downstream of it -- the forecast anchor
// and the next-settlement tile -- read the stored sign verbatim.
/**
 * @link ../../../../.docs/features/chains/card-statement-lifecycle.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('card_statements')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());
        $total = $connection->getQueryGrammar()->wrap('total_amount_minor');

        // The three terms name exactly the rows abs() mis-signed and nothing
        // else: a credit closing balance is the only positive total, and an open
        // balance still equal to it is one no settlement has moved. updated_at
        // is left alone because card_statements is derived per device, not sent.
        $connection->table('card_statements')
            ->where('state', CardStatementState::Open->value)
            ->where('total_amount_minor', '>', 0)
            ->whereColumn('open_balance_minor', 'total_amount_minor')
            ->update(['open_balance_minor' => $connection->raw('-'.$total)]);
    }

    // Forward-only: the figure this replaces was the magnitude of the one it
    // writes, so re-running is a no-op and reversing would restore a debt the
    // statement never carried.
    public function down(): void {}
};
