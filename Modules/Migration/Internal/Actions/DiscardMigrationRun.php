<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Exceptions\MigrationAlreadyConfirmedException;
use Modules\Migration\Models\MigrationRun;

final readonly class DiscardMigrationRun
{
    /**
     * @var list<string>
     */
    private const array STAGING_TABLES = [
        'migration_staging_categories',
        'migration_staging_accounts',
        'migration_staging_payees',
        'migration_staging_budget_assignments',
        'migration_staging_goals',
        'migration_staging_transactions',
        'migration_staging_unmapped_items',
    ];

    private const int ABANDONED_THRESHOLD_HOURS = 24;

    /**
     * @var list<string>
     */
    private const array NEVER_CONFIRMED_STATUSES = [
        MigrationRunStatus::Parsed->value,
        MigrationRunStatus::NeedsAttention->value,
    ];

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function __invoke(int $migrationRunId, User $user): void
    {
        /** @var MigrationRun $run */
        $run = MigrationRun::query()
            ->where('id', $migrationRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($run->status === MigrationRunStatus::Confirmed->value) {
            throw new MigrationAlreadyConfirmedException($migrationRunId);
        }

        $this->truncateStagingForRun($migrationRunId, $user);

        $run->update(['status' => MigrationRunStatus::Discarded->value]);
    }

    public function sweepAbandonedForUser(User $user): int
    {
        $cutoff = $this->clock->now()->subHours(self::ABANDONED_THRESHOLD_HOURS)->toDateTimeString();

        // Both never-confirmed states, not just the first-time one: a
        // reconciliation abandoned at its preview sits in 'needs_attention'
        // holding a whole export's staging rows, and wrote no domain row to
        // orphan by deleting it.
        $staleRunIds = $this->db->connection()->table('migration_runs')
            ->where('user_id', $user->id)
            ->whereIn('status', self::NEVER_CONFIRMED_STATUSES)
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
