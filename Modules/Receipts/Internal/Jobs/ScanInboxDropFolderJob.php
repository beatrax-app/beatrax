<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-user 5-minute scanner for the watched-folder secondary file-drop
 * path.
 *
 * Walks `storage/app/inbox-drop/{userId}/` for top-level `.eml` /
 * `.mbox` files (subdirectories — including the `processed/` and
 * `failed/` sinks this job populates — are skipped). For each file
 * the job invokes RecordReceipt against the raw bytes, exactly as
 * the wizard upload path does, and then atomically `rename()`s the
 * source file to one of:
 *
 *   storage/app/inbox-drop/{userId}/processed/{YYYY-MM}/{name}
 *   storage/app/inbox-drop/{userId}/failed/{YYYY-MM}/{name}
 *
 * A sibling `.error.txt` lands beside any failed file carrying the
 * thrown exception's message (≤500 chars) for triage.
 *
 * Concurrency contract:
 *  - `ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = userId`
 *    blocks a second per-user dispatch while a prior pass is still
 *    queued. The lock releases the moment a worker begins handle().
 *  - `tries = 3` + `backoff = [60, 300, 900]` matches the project-
 *    wide queued-job convention.
 *  - `uniqueFor = 600` (10 minutes) — comfortably longer than a
 *    healthy scan; short enough to unblock if a worker crashes
 *    mid-handle.
 *
 * Cross-user defence: the constructor takes an `int $userId` and the
 * inbox-drop path is computed via the injected Application's
 * `storagePath()` with the integer-cast userId. The inner scan loop
 * only walks the `{userId}/` subfolder; never walks above it. A
 * path-traversal attempt via a crafted filename cannot escape this
 * subtree because the file rename targets `basename($path)` only.
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * `Modules\Core\Public\Support\LockStore` helper: `uniqueVia()`
 * returns `LockStore::forUniqueJobs()`, which resolves the cache store
 * named by `config('cache.locks_store')`.
 *
 * Idempotency: re-running the job on the same root folder never
 * re-touches the `processed/` or `failed/` subtrees — the top-level
 * scan loop excludes any path containing those segments. A file
 * that re-appears in the root after being moved to processed/ (e.g.
 * a user copied it back manually) is treated as a fresh drop; the
 * RecordReceipt action's (user_id, provider_message_id) UNIQUE on
 * file_imports short-circuits the duplicate.
 */
final class ScanInboxDropFolderJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry attempts before final failure. */
    public int $tries = 3;

    /**
     * Exponential backoff in seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        Filesystem $files,
        Application $app,
        Clock $clock,
        RecordReceipt $recordReceipt,
        MboxIterator $mboxIterator,
        LoggerInterface $logger,
    ): void {
        $userRow = User::query()->where('id', $this->userId)->first();
        if (! $userRow instanceof User) {
            // The user was deleted between dispatch and worker pickup;
            // silently exit so the queue does not retry forever.
            return;
        }

        $baseDir = $app->storagePath('app/inbox-drop/'.$this->userId);
        if (! $files->isDirectory($baseDir)) {
            return;
        }

        $ym = $clock->now()->format('Y-m');
        $processedDir = $baseDir.'/processed/'.$ym;
        $failedDir = $baseDir.'/failed/'.$ym;

        $candidates = $this->topLevelCandidates($files, $baseDir);
        foreach ($candidates as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            try {
                if ($ext === 'eml') {
                    $bytes = $files->get($path);
                    ($recordReceipt)($bytes, $userRow, basename($path));
                } elseif ($ext === 'mbox') {
                    foreach ($mboxIterator->iterate($path) as $entry) {
                        $emlBytes = $entry['eml'];
                        if ($emlBytes === '') {
                            continue;
                        }
                        ($recordReceipt)($emlBytes, $userRow, basename($path).'@'.$entry['index']);
                    }
                } else {
                    // Unknown extension on a top-level file — leave it
                    // alone; the user may have dropped a README or
                    // scratch file. Don't move and don't error.
                    continue;
                }
                $this->moveTo($files, $path, $processedDir);
            } catch (Throwable $e) {
                $logger->warning(
                    'ScanInboxDropFolderJob: per-file processing failed.',
                    [
                        'user_id' => $this->userId,
                        'path' => $path,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                );
                try {
                    $this->moveTo($files, $path, $failedDir);
                    $errorPath = $failedDir.'/'.basename($path).'.error.txt';
                    $files->put($errorPath, substr($e->getMessage(), 0, 500));
                } catch (Throwable $inner) {
                    $logger->warning(
                        'ScanInboxDropFolderJob: failed to quarantine failed file.',
                        [
                            'user_id' => $this->userId,
                            'path' => $path,
                            'exception' => $inner::class,
                            'message' => $inner->getMessage(),
                        ],
                    );
                }
            }
        }
    }

    /**
     * Enumerate the top-level .eml / .mbox / other files inside
     * inbox-drop/{userId}/. Skips any path that lives under the
     * `processed/` or `failed/` subdirectories so re-runs never
     * touch quarantined or completed files.
     *
     * @return list<string>
     */
    private function topLevelCandidates(Filesystem $files, string $baseDir): array
    {
        $entries = $files->files($baseDir);
        $out = [];
        foreach ($entries as $info) {
            $path = $info->getPathname();
            $out[] = $path;
        }

        return $out;
    }

    private function moveTo(Filesystem $files, string $sourcePath, string $targetDir): void
    {
        $files->ensureDirectoryExists($targetDir, 0700, recursive: true);
        $target = $targetDir.'/'.basename($sourcePath);
        if (! @rename($sourcePath, $target)) {
            // POSIX rename failed (cross-device, permission). Fall
            // back to copy + unlink so the source still leaves the
            // top-level scan window.
            $files->copy($sourcePath, $target);
            $files->delete($sourcePath);
        }
    }
}
