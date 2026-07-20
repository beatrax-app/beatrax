<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Internal\Console\Support\DurationParser;
use Modules\Core\Public\Contracts\Clock;

/**
 * Maintenance operations on the Laravel-managed `failed_jobs` table.
 *
 * The action argument selects the subcommand; only `prune` is wired
 * for v1. Future verbs (`view`, `retry-all`, `clear`) can extend
 * without renaming the command surface.
 *
 *   php artisan beatrax:failed-jobs prune --older-than=30d
 *   php artisan beatrax:failed-jobs prune --older-than=7d --dry-run
 *
 * The duration token grammar (`30d`, `12h`, `2w`) is parsed by
 * `DurationParser`; invalid tokens exit 1 with the regex error
 * message. The default cutoff is 30 days, which matches the project's
 * conservative starting retention policy.
 *
 * Dry-run lists the would-be-deleted rows (capped at the first 50 to
 * keep the console output sane on large failure backlogs) and exits
 * without writing.
 *
 * The command is constructor-DI'd with `DatabaseManager`, `Clock`, and
 * `DurationParser`. No Laravel facade is imported. The
 * `BoundaryArchTest::noFacadeCallsFromCoreConsoleCommands` invariant
 * locks the DI-only posture.
 */
final class FailedJobsCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:failed-jobs
        {action=prune : Action to run (currently only "prune" is wired)}
        {--older-than=30d : Duration cutoff for the prune action (e.g. 30d, 7d, 12h, 2w)}
        {--dry-run : Print rows that WOULD be deleted without writing}';

    /** @var string */
    protected $description = 'Maintenance operations on the Laravel-managed failed_jobs table.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly DurationParser $duration,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        if ($action !== 'prune') {
            $this->error(sprintf('Unknown action: %s. Supported: prune.', $action));

            return self::FAILURE;
        }

        // `--older-than` has a default of `30d` so the option always resolves
        // to a string, but Larastan reports `string|null` since Symfony's
        // Option API permits NULL when an option carries no default. Coalesce
        // defensively so the grammar message still names the rejected token.
        $token = $this->option('older-than') ?? '';
        try {
            $cutoff = $this->duration->subFromNow($token, $this->clock->now());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->prune($cutoff);
    }

    private function prune(CarbonImmutable $cutoff): int
    {
        $connection = $this->db->connection();
        $cutoffString = $cutoff->toDateTimeString();

        if ($this->option('dry-run') === true) {
            $candidates = $connection->table('failed_jobs')
                ->where('failed_at', '<', $cutoffString)
                ->orderBy('failed_at', 'asc')
                ->limit(50)
                ->get(['id', 'queue', 'failed_at']);

            foreach ($candidates as $row) {
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                $queue = is_string($row->queue) ? $row->queue : '';
                $failedAt = is_string($row->failed_at) ? $row->failed_at : '';
                $this->line(sprintf("#%d\tqueue=%s\tfailed_at=%s", $id, $queue, $failedAt));
            }

            $total = $connection->table('failed_jobs')
                ->where('failed_at', '<', $cutoffString)
                ->count();

            $this->info(sprintf('Would delete %d rows (--dry-run; nothing written).', $total));

            return self::SUCCESS;
        }

        $deleted = $connection->table('failed_jobs')
            ->where('failed_at', '<', $cutoffString)
            ->delete();

        $remaining = $connection->table('failed_jobs')->count();

        $this->info(sprintf('Removed %d rows; %d remaining.', $deleted, $remaining));

        return self::SUCCESS;
    }
}
