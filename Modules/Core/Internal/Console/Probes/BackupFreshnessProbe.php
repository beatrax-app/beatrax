<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Throwable;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class BackupFreshnessProbe implements Probe
{
    private const BACKUP_AGE_MESSAGE = 'Most recent verified backup is %dh old.';

    private const int STALE_AFTER_HOURS = 48;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
        private readonly UserDataPathService $paths,
    ) {}

    public function label(): string
    {
        return 'Backup freshness';
    }

    public function run(): ProbeResult
    {
        try {
            $newestCompletedAt = $this->findNewestSidecarCompletedAt();
        } catch (Throwable $e) {
            return new ProbeResult(
                'critical',
                'Failed to read backups directory: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        if ($newestCompletedAt === null) {
            $this->recordOverdueAlert(null);

            return new ProbeResult(
                'warning',
                'No verified backups found under the backups directory.',
                ['hours_old' => null],
            );
        }

        $now = $this->clock->now();
        // Carbon 3.x `diffInHours` returns a float by default. Compute
        // the absolute integer hours between the sidecar timestamp and
        // now so direction (past / future) does not flip the sign.
        $hoursOld = (int) floor(abs($now->diffInHours($newestCompletedAt)));

        if ($hoursOld > self::STALE_AFTER_HOURS) {
            $this->recordOverdueAlert($hoursOld);

            return new ProbeResult(
                'warning',
                sprintf(self::BACKUP_AGE_MESSAGE, $hoursOld),
                ['hours_old' => $hoursOld],
            );
        }

        return new ProbeResult(
            'ok',
            sprintf(self::BACKUP_AGE_MESSAGE, $hoursOld),
            ['hours_old' => $hoursOld],
        );
    }

    // Returns null if the directory is missing, empty, or every sidecar is
    // unreadable/malformed — itself an "overdue" signal the caller treats
    // identically to a genuine ≥48h-old timestamp.
    private function findNewestSidecarCompletedAt(): ?CarbonImmutable
    {
        $backupsPath = $this->paths->backups();

        if (! $this->files->isDirectory($backupsPath)) {
            return null;
        }

        $entries = $this->files->files($backupsPath);

        $newest = null;
        foreach ($entries as $entry) {
            if (! str_ends_with($entry->getBasename(), '.meta.json')) {
                continue;
            }

            $raw = @file_get_contents($entry->getPathname());
            if (! is_string($raw)) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }

            $completedAt = $decoded['completed_at'] ?? null;
            if (! is_string($completedAt) || $completedAt === '') {
                continue;
            }

            try {
                $candidate = CarbonImmutable::parse($completedAt);
            } catch (Throwable) {
                continue;
            }

            if ($newest === null || $candidate->isAfter($newest)) {
                $newest = $candidate;
            }
        }

        return $newest;
    }

    private function recordOverdueAlert(?int $hoursOld): void
    {
        try {
            // Recency check uses the raw Query Builder (not Eloquent) since
            // larastan-strict-rules rejects chained Eloquent\Builder calls
            // after Model::query(). The cutoff is normalised to UTC because
            // SQLite's CURRENT_TIMESTAMP default writes in UTC, not app-local.
            $cutoff = $this->clock->now()->subHour()->setTimezone('UTC');
            $recentExists = $this->db->connection()->table('system_alerts')
                ->where('kind', 'backup_overdue')
                ->whereNull('acknowledged_at')
                ->where('created_at', '>=', $cutoff)
                ->exists();
            if ($recentExists) {
                return;
            }

            SystemAlert::create([
                'user_id' => null,
                'kind' => 'backup_overdue',
                'severity' => 'warning',
                'message' => $hoursOld === null
                    ? 'No verified backups found under the backups directory.'
                    : sprintf(self::BACKUP_AGE_MESSAGE, $hoursOld),
                'metadata' => [
                    'hours_old' => $hoursOld,
                    'backups_path' => $this->paths->backups(),
                ],
            ]);
        } catch (Throwable) {
            // Alert-write failure is non-fatal — the ProbeResult itself
            // is the load-bearing signal.
        }
    }
}
