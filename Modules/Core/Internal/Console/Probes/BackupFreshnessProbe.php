<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Throwable;

/**
 * Reads the newest `*.meta.json` sidecar under the backups directory
 * (resolved via the `core.backups_directory` container binding, wired
 * by `CoreServiceProvider` as a contextual `string $backupsPath`
 * argument). The sidecar's `completed_at` timestamp is compared to
 * `$clock->now()`; if no sidecar exists OR the newest is older than
 * 48 hours, the probe returns a `warning` result AND writes a
 * system-wide `system_alerts(kind=backup_overdue, severity=warning)`
 * row so the dashboard banner picks it up on the next page load.
 *
 * The Eloquent write happens through the framework's default model
 * connection (i.e. `SystemAlert::create([...])`). No `DatabaseManager`
 * dependency is required: the model owns its connection resolution and
 * an injected-but-unused readonly property trips
 * `larastan-strict-rules`' `property.onlyWritten` rule.
 *
 * The directory read + sidecar JSON decode are wrapped in try/catch
 * returning a `critical` `ProbeResult` so the probe never throws.
 */
final class BackupFreshnessProbe implements Probe
{
    private const int STALE_AFTER_HOURS = 48;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Clock $clock,
        private readonly string $backupsPath,
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
                sprintf('Most recent verified backup is %dh old.', $hoursOld),
                ['hours_old' => $hoursOld],
            );
        }

        return new ProbeResult(
            'ok',
            sprintf('Most recent verified backup is %dh old.', $hoursOld),
            ['hours_old' => $hoursOld],
        );
    }

    /**
     * Locate the newest `*.meta.json` sidecar by `completed_at`. Returns
     * null if the directory is missing, empty, or every sidecar is
     * unreadable / malformed (which is itself an "overdue" signal — the
     * caller treats null + ≥48h identically).
     */
    private function findNewestSidecarCompletedAt(): ?CarbonImmutable
    {
        if (! $this->files->isDirectory($this->backupsPath)) {
            return null;
        }

        $entries = $this->files->files($this->backupsPath);

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

    /**
     * Inserts a system-wide `system_alerts(backup_overdue)` row at
     * `warning` severity. The duplicate-suppression gate lives at the
     * service-query layer (the banner reads unacknowledged rows only
     * and groups by kind) so this writes unconditionally — accumulating
     * a per-doctor-run audit trail.
     *
     * The write is wrapped in try/catch so a missing system_alerts
     * table (e.g. probe invoked before migrations have run on a fresh
     * checkout) does NOT make the probe throw — the Probe contract
     * forbids that. The alert write is best-effort; the operator-
     * visible signal is the `warning` ProbeResult the caller returns.
     */
    private function recordOverdueAlert(?int $hoursOld): void
    {
        try {
            SystemAlert::create([
                'user_id' => null,
                'kind' => 'backup_overdue',
                'severity' => 'warning',
                'message' => $hoursOld === null
                    ? 'No verified backups found under storage/app/backups/.'
                    : sprintf('Most recent verified backup is %dh old.', $hoursOld),
                'metadata' => [
                    'hours_old' => $hoursOld,
                    'backups_path' => $this->backupsPath,
                ],
            ]);
        } catch (Throwable) {
            // Alert-write failure is non-fatal — the ProbeResult itself
            // is the load-bearing signal.
        }
    }
}
