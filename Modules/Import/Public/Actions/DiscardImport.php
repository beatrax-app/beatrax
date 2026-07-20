<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Exceptions\ImportAlreadyConfirmedException;
use Modules\Ledger\Models\ImportRun;

// Refuses to discard an already-confirmed run — flipping a confirmed
// row would orphan the ledger rows it created. The wizard UI never
// exposes Discard post-confirm, but this Public action is reachable
// programmatically, so the guard is enforced here regardless.
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
