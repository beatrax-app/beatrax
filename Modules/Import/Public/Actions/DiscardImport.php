<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Exceptions\ImportAlreadyConfirmedException;
use Modules\Ledger\Models\ImportRun;

/**
 * Discards a previewed import: flips the ImportRun's status to 'discarded'
 * and clears the preview cache. Used by the wizard's Discard button.
 *
 * Filters by `user_id` so a forged importRunId from another user produces a
 * 404 via `firstOrFail` rather than mutating the wrong row.
 *
 * Refuses to discard an already-confirmed run. Flipping a confirmed row to
 * 'discarded' would orphan the ledger rows it created — the audit trail
 * would claim the import was discarded while every transaction it
 * produced is still in the ledger. Mirrors the early-return guard
 * ConfirmImport applies to the same status. The wizard UI does not expose
 * Discard on the post-confirm results page, but the Public action is
 * reachable programmatically and via any future CLI / REST surface.
 */
final class DiscardImport
{
    public function __construct(private readonly PreviewCache $cache) {}

    public function __invoke(int $importRunId, User $user): void
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($importRun->status === 'confirmed') {
            throw new ImportAlreadyConfirmedException($importRunId);
        }

        $importRun->update(['status' => 'discarded']);

        $this->cache->forget($importRunId);
    }
}
