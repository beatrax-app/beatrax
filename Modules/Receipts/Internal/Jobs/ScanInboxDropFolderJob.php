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
use Modules\Core\Public\Support\BoundedRead;
use Modules\Core\Public\Support\LockStore;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Psr\Log\LoggerInterface;
use Throwable;

// Per-user 5-minute scanner for storage/app/inbox-drop/{userId}/.
// Top-level .eml/.mbox files run through RecordReceipt and then
// ReceiptLedgerBridge, exactly as the wizard upload path does, before
// moving atomically to a processed/ or failed/ subtree keyed by year-month.
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
        ReceiptLedgerBridge $bridge,
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

        // Shared across the whole scan, exactly as the inbox job shares one
        // across its walk: the run is created on the first parsed receipt and
        // adopted by every later one in the same hour.
        $importRunId = null;

        foreach ($this->topLevelCandidates($files, $baseDir) as $path) {
            try {
                if (! $this->recordCandidate($recordReceipt, $mboxIterator, $bridge, $userRow, $path, $importRunId)) {
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
        RecordReceipt $recordReceipt,
        MboxIterator $mboxIterator,
        ReceiptLedgerBridge $bridge,
        User $user,
        string $path,
        ?int &$importRunId,
    ): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'eml' => $this->recordEmlFile($recordReceipt, $bridge, $user, $path, $importRunId),
            'mbox' => $this->recordMboxFile($recordReceipt, $bridge, $mboxIterator, $user, $path, $importRunId),
            default => false,
        };
    }

    private function recordEmlFile(
        RecordReceipt $recordReceipt,
        ReceiptLedgerBridge $bridge,
        User $user,
        string $path,
        ?int &$importRunId,
    ): bool {
        $eml = BoundedRead::file(basename($path), $path, UploadLimits::MAX_MESSAGE_BYTES);

        $outcome = ($recordReceipt)($eml, $user, basename($path));
        $this->bridgeOutcome($bridge, $outcome, $user, $importRunId, SourceFormat::Eml);

        return true;
    }

    private function recordMboxFile(
        RecordReceipt $recordReceipt,
        ReceiptLedgerBridge $bridge,
        MboxIterator $mboxIterator,
        User $user,
        string $path,
        ?int &$importRunId,
    ): bool {
        foreach ($mboxIterator->iterate($path) as $entry) {
            if ($entry['eml'] === '') {
                continue;
            }
            // Index first: RecordReceipt reads the transport off this name's
            // extension, and a suffix past the dot spelled every archived
            // message a single .eml.
            $outcome = ($recordReceipt)($entry['eml'], $user, $entry['index'].'@'.basename($path));
            $this->bridgeOutcome($bridge, $outcome, $user, $importRunId, SourceFormat::Mbox);
        }

        return true;
    }

    // RecordReceipt persists the audit row and never writes to transactions.
    // Discarding its outcome here moved the file to processed/ and left the
    // ledger empty, which the reader had no screen to notice on.
    private function bridgeOutcome(
        ReceiptLedgerBridge $bridge,
        MatchOutcomeDto $outcome,
        User $user,
        ?int &$importRunId,
        SourceFormat $sourceFormat,
    ): void {
        if ($outcome->kind !== MatchOutcomeKind::Parsed || $outcome->parsed === null) {
            return;
        }

        $importRunId = $bridge->bridge($outcome->parsed, $user, $importRunId, $sourceFormat);
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
