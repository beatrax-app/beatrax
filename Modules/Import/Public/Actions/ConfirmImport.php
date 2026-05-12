<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;

/**
 * Confirms a previewed import. Loads the cached canonical batch, replays it
 * through Ledger's RecordsTransactions writer (which wraps the whole batch
 * in one DB transaction and silently drops fingerprint duplicates), updates
 * the ImportRun row with the result counts and `status='confirmed'`, and
 * clears the cache.
 *
 * Re-confirming an already-confirmed run returns a zero-result without
 * touching the ledger, so a refresh/back-button in the wizard cannot
 * double-import.
 */
final class ConfirmImport implements ConfirmsImports
{
    public function __construct(
        private readonly RecordsTransactions $recorder,
        private readonly PreviewCache $cache,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $importRunId, User $user): ImportConfirmResult
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($importRun->status === 'confirmed') {
            // Re-running an already-confirmed file: every row is a duplicate.
            // Surface the original inserted_count as `duplicates` so the
            // wizard's results page (and the IdempotencyContractTest) sees the
            // canonical "skipped N duplicates" summary.
            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 0,
                duplicates: $importRun->inserted_count,
                errors: 0,
            );
        }

        $canonical = $this->cache->getCanonical($importRunId);
        $preview = $this->cache->getPreview($importRunId);

        $errorCount = 0;
        $previewDuplicateCount = 0;
        if ($preview !== null) {
            foreach ($preview->rows as $row) {
                if ($row->status === 'error') {
                    $errorCount++;
                } elseif ($row->status === 'duplicate') {
                    $previewDuplicateCount++;
                }
            }
        }

        $result = ($this->recorder)($canonical);

        // The pipeline filters fingerprint-duplicates out of `$canonical` before
        // the recorder runs (so the preview screen can render the badge); the
        // recorder's own `duplicates` only catches race-condition collisions
        // between preview and confirm. Total duplicates = preview-detected +
        // recorder-detected.
        $totalDuplicates = $previewDuplicateCount + $result->duplicates;

        $importRun->update([
            'inserted_count' => $result->inserted,
            'duplicate_count' => $totalDuplicates,
            'error_count' => $errorCount,
            'confirmed_at' => $this->clock->now(),
            'status' => 'confirmed',
        ]);

        $this->cache->forget($importRunId);

        return new ImportConfirmResult(
            importRunId: $importRunId,
            inserted: $result->inserted,
            duplicates: $totalDuplicates,
            errors: $errorCount,
        );
    }
}
