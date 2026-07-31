<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Enums\MigrationRunStatus;
use Modules\Migration\Public\Exceptions\MigrationAlreadyConfirmedException;

/**
 * @link ../../../../.docs/features/migration/architecture.md
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

        if ($run->status === MigrationRunStatus::Confirmed->value) {
            throw new MigrationAlreadyConfirmedException($migrationRunId);
        }

        $this->truncateStagingForRun($migrationRunId, $user);

        $run->update(['status' => MigrationRunStatus::Discarded->value]);
    }

    public function sweepAbandonedForUser(User $user): int
    {
        $cutoff = $this->clock->now()->subHours(self::ABANDONED_THRESHOLD_HOURS)->toDateTimeString();

        $staleRunIds = $this->db->connection()->table('migration_runs')
            ->where('user_id', $user->id)
            ->where('status', MigrationRunStatus::Parsed->value)
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
