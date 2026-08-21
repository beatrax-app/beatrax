<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

// Back-populates card_statements from every ics_card statement_summary.
// insertOrIgnore against UNIQUE (user_id, account_id, period_start,
// period_end) makes a re-run a no-op. The negative closing_balance_minor
// sign is preserved verbatim; open_balance_minor is its absolute value.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $rows = $connection
            ->table('statement_summaries')
            ->join('accounts', 'accounts.id', '=', 'statement_summaries.account_id')
            ->where('accounts.kind', 'ics_card')
            ->select(
                'statement_summaries.user_id',
                'statement_summaries.account_id',
                'statement_summaries.import_run_id',
                'statement_summaries.period_start',
                'statement_summaries.period_end',
                'statement_summaries.closing_balance_minor',
            )
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now()->toDateTimeString();

        foreach ($rows as $row) {
            // Sources that carried no period metadata leave these NULL, and
            // the card_statements UNIQUE needs both boundaries.
            if ($row->period_start === null || $row->period_end === null) {
                continue;
            }

            $closing = is_numeric($row->closing_balance_minor) ? (int) $row->closing_balance_minor : 0;

            $connection->table('card_statements')->insertOrIgnore([
                'user_id' => $row->user_id,
                'account_id' => $row->account_id,
                'import_run_id' => $row->import_run_id,
                'period_start' => $row->period_start,
                'period_end' => $row->period_end,
                'total_amount_minor' => $closing,
                'open_balance_minor' => abs($closing),
                'state' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Forward-only: up() is idempotent, and the create migration's
        // down() drops the table.
    }
};
