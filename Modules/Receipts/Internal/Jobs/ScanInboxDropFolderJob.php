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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Receipts\Internal\Exceptions\InboxDropScanException;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Support\UploadLimits;
use Psr\Log\LoggerInterface;
use Throwable;

// Per-user 5-minute scanner for storage/app/inbox-drop/{userId}/.
// Top-level .eml/.mbox files run through RecordReceipt exactly as the
// wizard upload path does, then atomically move to a processed/ or
// failed/ subtree keyed by year-month.
final class ScanInboxDropFolderJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

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
            // exit silently so the queue does not retry forever.
            return;
        }

        $baseDir = $app->storagePath('app/inbox-drop/'.$this->userId);
        if (! $files->isDirectory($baseDir)) {
            return;
        }

        $ym = $clock->now()->format('Y-m');
        $processedDir = $baseDir.'/processed/'.$ym;
        $failedDir = $baseDir.'/failed/'.$ym;

        foreach ($this->topLevelCandidates($files, $baseDir) as $path) {
            try {
                if (! $this->recordCandidate($files, $recordReceipt, $mboxIterator, $userRow, $path)) {
                    // Unknown extension on a top-level file — leave it
                    // alone; don't move and don't error.
                    continue;
                }
                $this->moveTo($files, $path, $processedDir);
            } catch (Throwable $e) {
                $this->quarantine($files, $logger, $path, $failedDir, $e);
            }
        }
    }

    // Dispatches a top-level candidate by extension: .eml runs once,
    // .mbox streams each contained message. Returns false for an
    // unrecognised extension so the caller leaves the file untouched.
    private function recordCandidate(
        Filesystem $files,
        RecordReceipt $recordReceipt,
        MboxIterator $mboxIterator,
        User $user,
        string $path,
    ): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'eml' => $this->recordEmlFile($recordReceipt, $files, $user, $path),
            'mbox' => $this->recordMboxFile($recordReceipt, $mboxIterator, $user, $path),
            default => false,
        };
    }

    private function recordEmlFile(RecordReceipt $recordReceipt, Filesystem $files, User $user, string $path): bool
    {
        $size = @filesize($path);
        if ($size !== false && $size > UploadLimits::MAX_MESSAGE_BYTES) {
            throw InboxDropScanException::emlTooLarge(basename($path));
        }

        ($recordReceipt)($files->get($path), $user, basename($path));

        return true;
    }

    private function recordMboxFile(RecordReceipt $recordReceipt, MboxIterator $mboxIterator, User $user, string $path): bool
    {
        foreach ($mboxIterator->iterate($path) as $entry) {
            if ($entry['eml'] === '') {
                continue;
            }
            ($recordReceipt)($entry['eml'], $user, basename($path).'@'.$entry['index']);
        }

        return true;
    }

    // Moves a failed file into failed/{year-month}/ with a sibling
    // .error.txt excerpt. A secondary failure here is logged rather than
    // rethrown so one poisoned file cannot stall the whole scan.
    private function quarantine(Filesystem $files, LoggerInterface $logger, string $path, string $failedDir, Throwable $e): void
    {
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
                    ...SafeExceptionContext::describe($inner),
                ],
            );
        }
    }

    /**
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
            // POSIX rename failed (cross-device, permission) — copy +
            // unlink so the source still leaves the top-level scan.
            $files->copy($sourcePath, $target);
            $files->delete($sourcePath);
        }
    }
}
