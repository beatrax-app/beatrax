<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;

/**
 * Confirms a previewed import. Loads the cached canonical batch and the
 * cached pending-enrichments list, then inside a single DB transaction:
 *
 *  1. Replays the canonical batch through `RecordsTransactions` (which
 *     silently drops fingerprint duplicates and counts them).
 *  2. Applies the pending-enrichments via `AppliesEnrichments` (which
 *     UPDATEs each existing transactions row with a stronger source_ref
 *     and appends a provenance entry to `enriched_from`).
 *  3. Updates the ImportRun row with the inserted / duplicate / enriched
 *     / error counts and flips `status` to `confirmed`.
 *
 * The transaction wrapping both writers means a recorder failure cannot
 * land enrichments and an enrichment failure cannot land inserts —
 * confirm is atomic across both halves.
 *
 * Re-confirming an already-confirmed run returns a zero-action result
 * built from the persisted counts, so a refresh/back-button in the
 * wizard cannot double-import or double-enrich.
 */
final class ConfirmImport implements ConfirmsImports
{
    public function __construct(
        private readonly RecordsTransactions $recorder,
        private readonly AppliesEnrichments $applyEnrichments,
        private readonly PreviewCache $cache,
        private readonly DatabaseManager $db,
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
            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 0,
                duplicates: $importRun->inserted_count,
                enriched: $importRun->enriched_count,
                errors: 0,
            );
        }

        $canonical = $this->cache->getCanonical($importRunId);
        $enrichments = $this->cache->getEnrichments($importRunId) ?? [];
        $preview = $this->cache->getPreview($importRunId);

        if ($canonical === null) {
            // Cache miss (TTL expired, cache flushed). Leave the import_run
            // row on its previous status so the file's SHA-256 idempotency
            // short-circuit does not lock the user out, and surface a
            // typed exception so the wizard can render a re-upload prompt
            // instead of silently confirming nothing.
            throw new PreviewExpiredException($importRunId);
        }

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

        /** @var ImportConfirmResult $result */
        $result = $this->db->connection()->transaction(function () use (
            $canonical,
            $enrichments,
            $importRun,
            $user,
            $errorCount,
            $previewDuplicateCount,
        ): ImportConfirmResult {
            $recorderResult = ($this->recorder)($canonical, $user);
            $enrichedCount = ($this->applyEnrichments)($enrichments, $user);

            // The pipeline filters fingerprint-duplicates out of `$canonical`
            // before the recorder runs (so the preview screen can render the
            // badge); the recorder's own `duplicates` only catches race-
            // condition collisions between preview and confirm. Total
            // duplicates = preview-detected + recorder-detected.
            $totalDuplicates = $previewDuplicateCount + $recorderResult->duplicates;

            $importRun->update([
                'inserted_count' => $recorderResult->inserted,
                'duplicate_count' => $totalDuplicates,
                'enriched_count' => $enrichedCount,
                'error_count' => $errorCount,
                'confirmed_at' => $this->clock->now(),
                'status' => 'confirmed',
            ]);

            return new ImportConfirmResult(
                importRunId: $importRun->id,
                inserted: $recorderResult->inserted,
                duplicates: $totalDuplicates,
                enriched: $enrichedCount,
                errors: $errorCount,
            );
        });

        $this->cache->forget($importRunId);

        return $result;
    }
}
