<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Generator;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\EloquentAccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\ImportRun;
use RuntimeException;

/**
 * Preview action — the first half of the wizard's two-step ceremony.
 *
 * Flow:
 *  1. Hash the local file (SHA256). If the user already imported a file
 *     with the same hash AND that import landed (status='confirmed'),
 *     short-circuit with an empty preview — the file-layer idempotency
 *     guard backed by the UNIQUE `(user_id, sha256)` index on import_runs.
 *  2. Copy the upload into the app-owned `storage/app/imports/` folder so
 *     a stable, app-owned path is persisted on the ImportRun row. The
 *     Livewire temporary upload directory is garbage-collected after 24h
 *     and cleared by the OS on `/tmp` rotation, so it cannot be referenced
 *     later (e.g. by inline account naming after a coffee break).
 *  3. Otherwise create (or reuse) an ImportRun row with status='previewed'
 *     so the wizard has a stable id to reference.
 *  4. Run the ImportPipeline (parse → normalize → fingerprint) and cache
 *     the canonical batch under that import run id for the Confirm step.
 *
 * `runAndConfirm` is the test/CLI convenience that drives both halves in
 * one call.
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
            throw new RuntimeException('Could not compute SHA256 of uploaded file.');
        }

        /** @var ImportRun|null $existing */
        $existing = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha)
            ->first();

        if ($existing !== null && $existing->status === 'confirmed') {
            // File-layer idempotency: the SHA256 was already imported and
            // landed. Skip the (expensive, identical) re-parse and signal to
            // the wizard that nothing remains to do for this upload.
            return new ImportPreviewResult(
                importRunId: $existing->id,
                rows: [],
                accountsToName: [],
            );
        }

        $stablePath = $this->copyToStableLocation($localPath, $user, $sha, $sourceFormat);

        if ($existing !== null) {
            // Reused for a fresh preview (prior status was 'previewed' or
            // 'discarded'). Reset the audit fields so the wizard's status
            // and counters match the new attempt — otherwise a discarded
            // run would silently retain stale status/counts.
            $importRun = $this->resetPreviewedRun($existing, $sourceFormat, $stablePath);
        } else {
            try {
                $importRun = ImportRun::create([
                    'user_id' => $user->id,
                    'source_format' => $sourceFormat,
                    'raw_file_path' => $stablePath,
                    'sha256' => $sha,
                    'uploaded_at' => $this->clock->now(),
                    'status' => 'previewed',
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent preview for the same (user_id, sha256)
                // committed between our SELECT and this INSERT. Re-read
                // the winner's row and fall through to the same
                // confirmed-short-circuit / reuse-reset semantics.
                $raced = $this->reReadAfterRace($user, $sha);
                if ($raced->status === 'confirmed') {
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
            if ($row->status === 'enriched') {
                $enrichedCount++;
            }
        }

        $previewResult = new ImportPreviewResult(
            importRunId: $importRun->id,
            rows: $result['rows'],
            accountsToName: $result['unknownIbans'],
            enrichedCount: $enrichedCount,
        );

        $this->cache->put($importRun->id, $previewResult, $result['canonical'], $result['enrichments']);

        return $previewResult;
    }

    /**
     * Copies the upload to a deterministic, app-owned location keyed by the
     * user id and the file's SHA256. The same file uploaded twice resolves
     * to the same on-disk path; a no-op overwrite keeps the path stable. The
     * file extension matches the declared source format so the stored copy
     * round-trips through the format-specific HeaderSniffer on re-read.
     */
    private function copyToStableLocation(string $sourcePath, User $user, string $sha, string $sourceFormat): string
    {
        $disk = $this->storage->disk(self::STORAGE_DISK);
        $extension = match ($sourceFormat) {
            'camt053' => 'xml',
            'mt940' => 'sta',
            'ics-pdf' => 'pdf',
            'eml' => 'eml',
            'mbox' => 'mbox',
            default => 'csv',
        };
        $relative = sprintf('%s/%d/%s.%s', self::STORAGE_PREFIX, $user->id, $sha, $extension);

        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read upload source file: %s', $sourcePath));
        }

        if (! $disk->put($relative, $contents)) {
            throw new RuntimeException(sprintf('Could not persist upload to stable storage: %s', $relative));
        }

        $absolute = $disk->path($relative);
        if ($absolute === '') {
            throw new RuntimeException('Stable storage disk does not expose absolute paths.');
        }

        return $absolute;
    }

    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv', ?BankCsvFormatHint $formatHint = null): ImportConfirmResult
    {
        $preview = $this->runFromUpload($localPath, $sourceFormat, $user, $originalFilename, $formatHint);

        return ($this->confirmAction)($preview->importRunId, $user);
    }

    /**
     * Mirrors `runFromUpload()`'s shape for a remote-fetch caller (e.g.
     * `Modules\OpenBanking`'s Enable Banking adapter) with no local file
     * to hash and copy:
     *
     *  1. Dedup at the `ImportRun` grain on `$idempotencyKey` instead of a
     *     file SHA256 (there is no file — see the contract's docblock for
     *     the deterministic-window-key requirement).
     *  2. Create-or-reuse an `ImportRun` row with `status='previewed'`.
     *     `raw_file_path` has no real file to point at, so it carries a
     *     synthetic, deterministic marker derived from the same key —
     *     stable across re-fetches of the same window, distinct across
     *     windows, and never mistaken for a real on-disk path.
     *  3. Run `ImportPipeline::previewFromGenerator()` (not `preview()`) —
     *     the generator-driven counterpart that skips
     *     `persistStatementMetadata()` (no CAMT-shaped statement summary
     *     for a point-in-time OB balance).
     *  4. Count enriched rows, wrap in `ImportPreviewResult`, cache via
     *     `PreviewCache::put()` — identical to the upload path.
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

        if ($existing !== null && $existing->status === 'confirmed') {
            // Same short-circuit as runFromUpload(): the window was
            // already fetched and landed, so there is nothing left to
            // preview for this exact idempotency key.
            return new ImportPreviewResult(
                importRunId: $existing->id,
                rows: [],
                accountsToName: [],
            );
        }

        if ($existing !== null) {
            // Reused row from a prior 'previewed'/'discarded' attempt —
            // reset audit fields like runFromUpload()'s reuse branch so
            // stale counters/status never leak. `raw_file_path` keeps
            // its deterministic synthetic marker (passed null, untouched).
            $importRun = $this->resetPreviewedRun($existing, $sourceFormat, null);
        } else {
            try {
                $importRun = ImportRun::create([
                    'user_id' => $user->id,
                    'source_format' => $sourceFormat,
                    'raw_file_path' => $this->syntheticRawFilePath($idempotencyKey),
                    'sha256' => $idempotencyKey,
                    'uploaded_at' => $this->clock->now(),
                    'status' => 'previewed',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Same-key concurrent preview won the insert race;
                // re-read and fall through to the reuse/confirmed semantics
                // rather than 500-ing the loser.
                $raced = $this->reReadAfterRace($user, $idempotencyKey);
                if ($raced->status === 'confirmed') {
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
            if ($row->status === 'enriched') {
                $enrichedCount++;
            }
        }

        $previewResult = new ImportPreviewResult(
            importRunId: $importRun->id,
            rows: $result['rows'],
            accountsToName: $result['unknownIbans'],
            enrichedCount: $enrichedCount,
        );

        $this->cache->put($importRun->id, $previewResult, $result['canonical'], $result['enrichments']);

        return $previewResult;
    }

    /**
     * `raw_file_path` is a required, non-nullable column with no FK to
     * storage — it is a plain audit string. There is no on-disk file for a
     * remote fetch, so this carries a marker that is deterministic (same
     * key -> same marker, matching the row-reuse behaviour above) and is
     * unambiguously not a real filesystem path.
     */
    private function syntheticRawFilePath(string $idempotencyKey): string
    {
        return sprintf('open-banking://%s', $idempotencyKey);
    }

    /**
     * Reset a reused `ImportRun` row's audit fields for a fresh preview
     * attempt — shared by the normal reuse branch and the
     * unique-violation race-recovery path. `$stablePath` is null for the
     * remote-fetch path (its synthetic `raw_file_path` marker is left
     * unchanged); non-null for the upload path.
     */
    private function resetPreviewedRun(ImportRun $run, string $sourceFormat, ?string $stablePath): ImportRun
    {
        $attributes = [
            'source_format' => $sourceFormat,
            'status' => 'previewed',
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

    /**
     * Re-read the row a concurrent preview just committed under the same
     * `(user_id, sha256)` unique key after our own INSERT lost the race.
     * The row must exist — the violation proves it was committed — so a
     * null here is a genuine, unexpected invariant break.
     */
    private function reReadAfterRace(User $user, string $sha256): ImportRun
    {
        /** @var ImportRun|null $raced */
        $raced = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha256)
            ->first();

        if ($raced === null) {
            throw new RuntimeException(
                'RunImport: import_runs row for the raced key vanished immediately after a unique-constraint violation.'
            );
        }

        return $raced;
    }
}
