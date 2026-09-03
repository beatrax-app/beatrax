<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Exceptions\MigrationAlreadyConfirmedException;
use Modules\Migration\Models\MigrationRun;
use Modules\Sync\Public\Services\DependentRowCascade;

final readonly class DiscardMigrationRun
{
    use CoercesScalars;

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
        private Dispatcher $events,
        private DependentRowCascade $cascade,
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

        $this->discardStagingForRun($migrationRunId, $user->id);

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
            $this->discardStagingForRun(self::toInt($runId), $user->id);

            $deleted = $this->db->connection()->table('migration_runs')
                ->where('id', $runId)
                ->where('user_id', $user->id)
                ->delete();

            $reclaimed += $deleted;
        }

        return $reclaimed;
    }

    // The staging tables are the run's own rows, so the foreign-key graph
    // already names them. Repeating that list here is what let the abandoned
    // sweep delete a run and leave its staging rows to the database.
    private function discardStagingForRun(int $migrationRunId, int $userId): void
    {
        foreach ($this->cascade->delete('migration_runs', $migrationRunId, $userId) as $event) {
            $this->events->dispatch($event);
        }
    }
}
