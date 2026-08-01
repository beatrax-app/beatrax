<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * One-shot back-population from existing Phase 3 `statement_summaries`
 * rows into the new `card_statements` table.
 *
 * For every statement_summary whose owning account has kind `ics_card`,
 * insert a corresponding card_statements row in state `open`. The
 * insert is idempotent via `insertOrIgnore` against the UNIQUE
 * (user_id, account_id, period_start, period_end) constraint, so a
 * re-run (in dev or after a fresh migrate:refresh) produces zero new
 * rows.
 *
 * Sign convention: Phase 3's IcsPdfAdapter writes `closing_balance_minor`
 * as a NEGATIVE value (the amount the user owes). `total_amount_minor`
 * on card_statements preserves that sign verbatim; `open_balance_minor`
 * is the absolute value (positive amount remaining to settle). Tests in
 * Wave 1 lock this convention by asserting both columns against a known
 * seeded statement_summary row.
 *
 * Forward-only by design: the `down()` method is intentionally a no-op
 * because re-running `up()` is already a no-op via insertOrIgnore. To
 * fully roll back, drop the card_statements table via the create
 * migration's `down()`.
 */
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
            // statement_summaries.period_start/period_end may be NULL for
            // sources that didn't carry the period in their statement
            // metadata. Skip those — card_statements UNIQUE requires both
            // boundaries, and the dashboard tile needs them for the due-
            // date forecast.
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
        // Forward-only — re-running up() is idempotent. To fully roll
        // back, drop the card_statements table via the create migration's
        // down().
    }
};
