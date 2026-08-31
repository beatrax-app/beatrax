<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Generator;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Import\Internal\Dto\PreviewHead;
use Modules\Import\Internal\Exceptions\RacedImportRunVanishedException;
use Modules\Import\Internal\Exceptions\UploadStagingException;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Services\RemoteFetchPath;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\EloquentAccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\ImportRunStatus;

/**
 * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
 */
final readonly class RunImport implements RunsImports
{
    private const string STORAGE_DISK = 'local';

    private const string STORAGE_PREFIX = 'imports';

    public function __construct(
        private ImportPipeline $pipeline,
        private PreviewCache $cache,
        private Clock $clock,
        private ConfirmImport $confirmAction,
        private StorageFactory $storage,
    ) {}

    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename, ?BankCsvFormatHint $formatHint = null): ImportPreviewResult
    {
        $sha = hash_file('sha256', $localPath);
        if (! is_string($sha)) {
            throw UploadStagingException::sha256Unavailable();
        }

        $existing = $this->findRun($user, $sha);

        if ($existing !== null && $existing->status === ImportRunStatus::Confirmed->value) {
            // This SHA256 already imported and landed, so the re-parse would
            // be identical and expensive.
            return new ImportPreviewResult(
                importRunId: $existing->id,
                rows: [],
                accountsToName: [],
            );
        }

        $stablePath = $this->copyToStableLocation($localPath, $user, $sha, $sourceFormat);

        if ($existing !== null) {
            // Reset the audit fields, or a reused 'discarded' run keeps its
            // stale status and counters through the new attempt.
            $importRun = $this->resetPreviewedRun($existing, $sourceFormat, $stablePath);
        } else {
            try {
                $importRun = $this->createRun($user, $sha, [
                    'user_id' => $user->id,
                    'source_format' => $sourceFormat,
                    'raw_file_path' => $stablePath,
                    'sha256' => $sha,
                    'uploaded_at' => $this->clock->now(),
                    'status' => ImportRunStatus::Previewed->value,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent preview for the same (user_id, sha256)
                // committed between the SELECT and this INSERT.
                $raced = $this->reReadAfterRace($user, $sha);
                if ($raced->status === ImportRunStatus::Confirmed->value) {
                    return new ImportPreviewResult(
                        importRunId: $raced->id,
                        rows: [],
                        accountsToName: [],
                    );
                }
                $importRun = $this->resetPreviewedRun($raced, $sourceFormat, $stablePath);
            }
        }

        $accounts = new EloquentAccountResolver($user);

        return $this->windowOnto($this->pipeline->preview(
            $stablePath,
            $sourceFormat,
            $accounts,
            $user,
            $importRun->id,
            $this->cache->writer($importRun->id),
            $formatHint,
        ));
    }

    // The name is the content hash, so a second upload of the same file
    // publishes identical bytes to the same path. The extension follows the
    // declared format so HeaderSniffer still recognises the stored copy.
    private function copyToStableLocation(string $sourcePath, User $user, string $sha, string $sourceFormat): string
    {
        $disk = $this->storage->disk(self::STORAGE_DISK);
        $extension = match ($sourceFormat) {
            SourceFormat::Camt053->value => 'xml',
            SourceFormat::Mt940->value => 'sta',
            'ics-pdf' => 'pdf',
            'eml' => 'eml',
            'mbox' => 'mbox',
            default => 'csv',
        };
        $relative = sprintf('%s/%d/%s.%s', self::STORAGE_PREFIX, $user->id, $sha, $extension);

        $expectedBytes = @filesize($sourcePath);
        $source = @fopen($sourcePath, 'rb');

        if ($expectedBytes === false || $source === false) {
            throw UploadStagingException::sourceUnreadable($sourcePath);
        }

        // put() truncates the destination first, and the caller hands that
        // path to the parser moments later. rename() is atomic, and the name
        // is the content hash, so either copy holds the same bytes.
        $staged = $relative.'.'.bin2hex(random_bytes(8)).'.part';

        try {
            $written = $disk->writeStream($staged, $source);
        } finally {
            fclose($source);
        }

        if ($written === false) {
            throw UploadStagingException::persistFailed($relative);
        }

        $absolute = $disk->path($relative);
        $stagedAbsolute = $disk->path($staged);

        if ($absolute === '' || $stagedAbsolute === '') {
            $disk->delete($staged);

            throw UploadStagingException::absolutePathsUnsupported();
        }

        // A short write is not an error to file_put_contents and Flysystem only
        // checks for false, so a device out of space stages a truncated
        // statement that still carries a valid header -- and a statement
        // imported short is a wrong number in the ledger.
        $stagedBytes = @filesize($stagedAbsolute);

        if ($stagedBytes !== $expectedBytes) {
            $disk->delete($staged);

            throw UploadStagingException::stagedCopyIsShort($relative, $expectedBytes, $stagedBytes === false ? 0 : $stagedBytes);
        }

        if (! @rename($stagedAbsolute, $absolute)) {
            $disk->delete($staged);

            throw UploadStagingException::persistFailed($relative);
        }

        return $absolute;
    }

    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv', ?BankCsvFormatHint $formatHint = null): ImportConfirmResult
    {
        $preview = $this->runFromUpload($localPath, $sourceFormat, $user, $originalFilename, $formatHint);

        return ($this->confirmAction)($preview->importRunId, $user);
    }

    /**
     * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
     *
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     */
    public function runFromRemoteFetch(Generator $sourceRows, string $sourceFormat, User $user, string $idempotencyKey): ImportPreviewResult
    {
        $existing = $this->findRun($user, $idempotencyKey);

        if ($existing !== null && $existing->status === ImportRunStatus::Confirmed->value) {
            // Same short-circuit as runFromUpload(): this window already
            // fetched and landed.
            return new ImportPreviewResult(
                importRunId: $existing->id,
                rows: [],
                accountsToName: [],
            );
        }

        if ($existing !== null) {
            // As in runFromUpload()'s reuse branch, minus raw_file_path: the
            // synthetic marker is passed null and left alone.
            $importRun = $this->resetPreviewedRun($existing, $sourceFormat, null);
        } else {
            try {
                $importRun = $this->createRun($user, $idempotencyKey, [
                    'user_id' => $user->id,
                    'source_format' => $sourceFormat,
                    'raw_file_path' => RemoteFetchPath::forKey($idempotencyKey),
                    'sha256' => $idempotencyKey,
                    'uploaded_at' => $this->clock->now(),
                    'status' => ImportRunStatus::Previewed->value,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A same-key concurrent preview won the insert race; the
                // loser re-reads rather than 500-ing.
                $raced = $this->reReadAfterRace($user, $idempotencyKey);
                if ($raced->status === ImportRunStatus::Confirmed->value) {
                    return new ImportPreviewResult(
                        importRunId: $raced->id,
                        rows: [],
                        accountsToName: [],
                    );
                }
                $importRun = $this->resetPreviewedRun($raced, $sourceFormat, null);
            }
        }

        $accounts = new EloquentAccountResolver($user);

        return $this->windowOnto($this->pipeline->previewFromGenerator(
            $sourceRows,
            $sourceFormat,
            $accounts,
            $user,
            $importRun->id,
            $this->cache->writer($importRun->id),
        ));
    }

    // The rows come back off the chunks the pipeline just wrote, a window at a
    // time, rather than being carried out of it. What the caller gets is the
    // head plus that window; past it rowsAreComplete() is false and says so.
    private function windowOnto(PreviewHead $head): ImportPreviewResult
    {
        return PreviewCache::resultFrom(
            $head,
            $this->cache->rows($head->importRunId, 0, PreviewCache::RESULT_ROW_WINDOW),
        );
    }

    private function resetPreviewedRun(ImportRun $run, string $sourceFormat, ?string $stablePath): ImportRun
    {
        $attributes = [
            'source_format' => $sourceFormat,
            'status' => ImportRunStatus::Previewed->value,
            'uploaded_at' => $this->clock->now(),
            'confirmed_at' => null,
            'inserted_count' => 0,
            'duplicate_count' => 0,
            'error_count' => 0,
        ];

        if ($stablePath !== null) {
            $attributes['raw_file_path'] = $stablePath;
        }

        $run->update($attributes);

        return $run;
    }

    // The run is read back by the (user_id, sha256) UNIQUE rather than carried
    // out of create(): create() ends in insertGetId(), lastInsertId() is per
    // connection, and the badge listener writes a `cache` row from inside this
    // INSERT's own event -- the preview would name a run the confirm cannot find.
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
     */
    private function createRun(User $user, string $sha256, array $attributes): ImportRun
    {
        ImportRun::create($attributes);

        return $this->findRun($user, $sha256) ?? throw new IdReadBackFailedException('import_runs');
    }

    // The UniqueConstraintViolationException that led here proves the row
    // committed, so a null read is a real invariant break.
    private function reReadAfterRace(User $user, string $sha256): ImportRun
    {
        return $this->findRun($user, $sha256)
            ?? throw new RacedImportRunVanishedException($user->id, $sha256);
    }

    private function findRun(User $user, string $sha256): ?ImportRun
    {
        /** @var ImportRun|null $found */
        $found = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha256)
            ->first();

        return $found;
    }
}
