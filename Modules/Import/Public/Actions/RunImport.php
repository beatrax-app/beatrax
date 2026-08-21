<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Generator;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Enums\PreviewRowStatus;
use Modules\Import\Internal\Exceptions\RacedImportRunVanishedException;
use Modules\Import\Internal\Exceptions\UploadStagingException;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
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
final class RunImport implements RunsImports
{
    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'imports';

    public function __construct(
        private readonly ImportPipeline $pipeline,
        private readonly PreviewCache $cache,
        private readonly Clock $clock,
        private readonly ConfirmImport $confirmAction,
        private readonly StorageFactory $storage,
    ) {}

    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename, ?BankCsvFormatHint $formatHint = null): ImportPreviewResult
    {
        $sha = hash_file('sha256', $localPath);
        if (! is_string($sha)) {
            throw UploadStagingException::sha256Unavailable();
        }

        /** @var ImportRun|null $existing */
        $existing = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha)
            ->first();

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
                $importRun = ImportRun::create([
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
        $result = $this->pipeline->preview($stablePath, $sourceFormat, $accounts, $user, $importRun->id, $formatHint);

        $enrichedCount = 0;
        foreach ($result['rows'] as $row) {
            if ($row->status === PreviewRowStatus::Enriched->value) {
                $enrichedCount++;
            }
        }

        $previewResult = new ImportPreviewResult(
            importRunId: $importRun->id,
            rows: $result['rows'],
            accountsToName: $result['unknownIbans'],
            enrichedCount: $enrichedCount,
            fileFailureReason: $result['fileFailureReason'],
            fileFailureDetail: $result['fileFailureDetail'],
        );

        $this->cache->put($importRun->id, $previewResult, $result['canonical'], $result['enrichments']);

        return $previewResult;
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

        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            throw UploadStagingException::sourceUnreadable($sourcePath);
        }

        // put() truncates the destination first, and the caller hands that
        // path to the parser moments later. rename() is atomic, and the name
        // is the content hash, so either copy holds the same bytes.
        $staged = $relative.'.'.bin2hex(random_bytes(8)).'.part';

        if (! $disk->put($staged, $contents)) {
            throw UploadStagingException::persistFailed($relative);
        }

        $absolute = $disk->path($relative);
        $stagedAbsolute = $disk->path($staged);

        if ($absolute === '' || $stagedAbsolute === '') {
            $disk->delete($staged);

            throw UploadStagingException::absolutePathsUnsupported();
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
        /** @var ImportRun|null $existing */
        $existing = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $idempotencyKey)
            ->first();

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
                $importRun = ImportRun::create([
                    'user_id' => $user->id,
                    'source_format' => $sourceFormat,
                    'raw_file_path' => $this->syntheticRawFilePath($idempotencyKey),
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
        $result = $this->pipeline->previewFromGenerator($sourceRows, $sourceFormat, $accounts, $user, $importRun->id);

        $enrichedCount = 0;
        foreach ($result['rows'] as $row) {
            if ($row->status === PreviewRowStatus::Enriched->value) {
                $enrichedCount++;
            }
        }

        $previewResult = new ImportPreviewResult(
            importRunId: $importRun->id,
            rows: $result['rows'],
            accountsToName: $result['unknownIbans'],
            enrichedCount: $enrichedCount,
            fileFailureReason: $result['fileFailureReason'],
            fileFailureDetail: $result['fileFailureDetail'],
        );

        $this->cache->put($importRun->id, $previewResult, $result['canonical'], $result['enrichments']);

        return $previewResult;
    }

    // raw_file_path is a required audit string with no FK to storage, so the
    // remote-fetch path needs a deterministic, obviously-not-a-path marker.
    private function syntheticRawFilePath(string $idempotencyKey): string
    {
        return sprintf('open-banking://%s', $idempotencyKey);
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

    // The UniqueConstraintViolationException that led here proves the row
    // committed, so a null read is a real invariant break.
    private function reReadAfterRace(User $user, string $sha256): ImportRun
    {
        /** @var ImportRun|null $raced */
        $raced = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha256)
            ->first();

        if ($raced === null) {
            throw new RacedImportRunVanishedException($user->id, $sha256);
        }

        return $raced;
    }
}
