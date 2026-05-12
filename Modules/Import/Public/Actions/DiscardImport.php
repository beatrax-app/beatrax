<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Ledger\Models\ImportRun;

/**
 * Discards a previewed import: flips the ImportRun's status to 'discarded'
 * and clears the preview cache. Used by the wizard's Discard button.
 *
 * Filters by `user_id` so a forged importRunId from another user produces a
 * 404 via `firstOrFail` rather than mutating the wrong row.
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

        $importRun->update(['status' => 'discarded']);

        $this->cache->forget($importRunId);
    }
}
