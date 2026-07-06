<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Exceptions\MigrationAlreadyConfirmedException;

/**
 * Discards a previewed-but-not-yet-confirmed migration run: truncates its
 * `migration_staging_*` rows (D-07 — scratch, safe to discard without
 * touching any domain table) and flips the run's status to 'discarded',
 * preserving the audit row itself. Mirrors
 * `Modules\Import\Public\Actions\DiscardImport`'s identical shape.
 *
 * Filters by `user_id` so a forged `migrationRunId` from another user
 * produces a 404 via `firstOrFail`, not a mutation of the wrong row
 * (T-13.5-17).
 *
 * Refuses to discard an already-confirmed run: flipping a confirmed row to
 * 'discarded' would orphan the domain rows `PromoteStagingToDomain` already
 * created — the audit trail would falsely claim the migration was discarded
 * while every category/account/transaction it promoted is still live.
 *
 * `sweepAbandonedForUser()` is a small self-contained abandoned-run sweep: it
 * deletes (cascade-wiping their staging via the FK) any of THIS user's
 * never-confirmed runs older than the threshold. Every delete is scoped by
 * BOTH `id` AND `user_id` explicitly — never a bulk unscoped truncate
 * (T-13.5-19) — so it can never touch another user's rows even if invoked
 * from a future scheduled hook that iterates all users.
 */
final class DiscardMigrationRun
{
    /**
     * @var list<string>
     */
    private const STAGING_TABLES = [
        'migration_staging_categories',
        'migration_staging_accounts',
        'migration_staging_payees',
        'migration_staging_budget_assignments',
        'migration_staging_goals',
        'migration_staging_transactions',
        'migration_staging_unmapped_items',
    ];

    /** Hours a never-confirmed run is left staged before the sweep reclaims it. */
    private const ABANDONED_THRESHOLD_HOURS = 24;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $migrationRunId, User $user): void
    {
        /** @var MigrationRun $run */
        $run = MigrationRun::query()
            ->where('id', $migrationRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($run->status === 'confirmed') {
            throw new MigrationAlreadyConfirmedException($migrationRunId);
        }

        $this->truncateStagingForRun($migrationRunId, $user);

        $run->update(['status' => 'discarded']);
    }

    /**
     * Deletes every never-confirmed run older than the abandoned threshold
     * for THIS user, cascade-wiping its staging rows via the FK. Returns the
     * number of runs reclaimed.
     */
    public function sweepAbandonedForUser(User $user): int
    {
        $cutoff = $this->clock->now()->subHours(self::ABANDONED_THRESHOLD_HOURS)->toDateTimeString();

        $staleRunIds = $this->db->connection()->table('migration_runs')
            ->where('user_id', $user->id)
            ->where('status', 'parsed')
            ->where('created_at', '<', $cutoff)
            ->pluck('id');

        $reclaimed = 0;
        foreach ($staleRunIds as $runId) {
            $deleted = $this->db->connection()->table('migration_runs')
                ->where('id', $runId)
                ->where('user_id', $user->id)
                ->delete();

            $reclaimed += $deleted;
        }

        return $reclaimed;
    }

    private function truncateStagingForRun(int $migrationRunId, User $user): void
    {
        foreach (self::STAGING_TABLES as $table) {
            $this->db->connection()->table($table)
                ->where('user_id', $user->id)
                ->where('migration_run_id', $migrationRunId)
                ->delete();
        }
    }
}
