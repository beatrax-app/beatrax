<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupSidecar;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\StoredCopy;
use SplFileInfo;
use Throwable;

// How old the newest verified backup is, and the alert that says so. It was a
// private half of BackupFreshnessProbe, which runs only from `beatrax:doctor` --
// a command neither shipped bundle can invoke, so the one thing that could raise
// the banner was a terminal the reader does not have.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final readonly class BackupFreshness
{
    // Two days, not one: the daily run has to miss a whole day before a reader
    // is told anything, or a laptop shut over a weekend accuses the app of a
    // fault it does not have.
    public const int STALE_AFTER_HOURS = 48;

    public function __construct(
        private Filesystem $files,
        private Clock $clock,
        private DatabaseManager $db,
        private UserDataPathService $paths,
        private SystemAlertWriter $alerts,
    ) {}

    // Null if the directory is missing, empty, or every sidecar is unreadable,
    // malformed, or names a copy that is gone. The caller decides what that
    // means: to the doctor it is the same warning as a stale one, and at boot
    // it is a fresh install.
    public function newestVerifiedAt(): ?CarbonImmutable
    {
        $backupsPath = $this->paths->backups();

        if (! $this->files->isDirectory($backupsPath)) {
            return null;
        }

        $newest = null;
        foreach ($this->files->files($backupsPath) as $entry) {
            $candidate = $this->sidecarCompletedAt($entry);
            if ($candidate !== null && ($newest === null || $candidate->isAfter($newest))) {
                $newest = $candidate;
            }
        }

        return $newest;
    }

    // Carbon 3.x `diffInHours` returns a float by default. The absolute integer
    // hours, so direction — past or future — cannot flip the sign.
    public function hoursSince(CarbonImmutable $completedAt): int
    {
        return (int) floor(abs($this->clock->now()->diffInHours($completedAt)));
    }

    public function isStale(int $hoursOld): bool
    {
        return $hoursOld > self::STALE_AFTER_HOURS;
    }

    public function backupsPath(): string
    {
        return $this->paths->backups();
    }

    // De-duplicated on the hour and on an open row of the same kind, so a
    // restart storm cannot produce an alert storm. A write failure is
    // swallowed: the caller's own signal — a probe line, or a boot that must
    // not halt — is the load-bearing one.
    public function raiseOverdue(?int $hoursOld): void
    {
        try {
            // Recency uses the raw Query Builder, not Eloquent, because
            // larastan-strict-rules rejects chained Eloquent\Builder calls after
            // Model::query(). SystemAlert stamps created_at off the app clock,
            // so the cutoff is built in that frame rather than in UTC.
            $cutoff = Instant::appLocal($this->clock->now()->subHour());
            $recentExists = $this->db->connection()->table('system_alerts')
                ->where('kind', BackupAlertKind::Overdue->value)
                ->whereNull('acknowledged_at')
                ->where('created_at', '>=', $cutoff)
                ->exists();
            if ($recentExists) {
                return;
            }

            // The probe line keeps its English: that one is read in a console by
            // whoever ran the doctor. This row is read later, on whichever
            // device and in whichever language, so the line rides in metadata
            // and the column keeps a sentence a peer can still show.
            $line = $hoursOld === null
                ? CopyLine::of('core::alerts.messages.backup_none_found')
                : CopyLine::of('core::alerts.messages.backup_overdue', ['hours' => $hoursOld]);

            $this->alerts->raiseOnceSystemWide(
                kind: BackupAlertKind::Overdue->value,
                severity: SystemAlertSeverity::Warning->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line) + [
                    'hours_old' => $hoursOld,
                    'backups_path' => $this->paths->backups(),
                ],
                window: SystemAlertWriter::hourWindow($this->clock->now()),
            );
        } catch (Throwable) {
            // Alert-write failure is non-fatal — see the note above.
        }
    }

    // One sidecar's completed_at, or null when the entry is not a sidecar, is
    // unreadable or malformed, or carries no parseable date.
    private function sidecarCompletedAt(SplFileInfo $entry): ?CarbonImmutable
    {
        if (! str_ends_with($entry->getBasename(), BackupSidecar::SUFFIX)) {
            return null;
        }

        // A date off a sidecar whose copy is gone is the freshness of nothing,
        // and it is exactly what keeps the overdue banner down on a device
        // whose backups folder holds no backup at all.
        if (! BackupSidecar::describesAPresentBackup($entry->getPathname())) {
            return null;
        }

        $completedAt = $this->readCompletedAtField($entry->getPathname());

        return $completedAt === null ? null : SafeDate::parseOrNull($completedAt);
    }

    private function readCompletedAtField(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $completedAt = is_array($decoded) ? ($decoded['completed_at'] ?? null) : null;

        return is_string($completedAt) && $completedAt !== '' ? $completedAt : null;
    }
}
