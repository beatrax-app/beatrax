<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\ImportAlreadyConfirmedException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\ImportRunStatus;

// Discarding a confirmed run would orphan the ledger rows it created. The
// wizard hides Discard after confirm, but this action is Public and
// reachable programmatically, so the guard lives here.
final readonly class DiscardImport
{
    public function __construct(private PreviewCache $cache) {}

    public function __invoke(int $importRunId, User $user): void
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($importRun->status === ImportRunStatus::Confirmed->value) {
            throw new ImportAlreadyConfirmedException($importRunId);
        }

        $importRun->update(['status' => ImportRunStatus::Discarded->value]);

        $this->cache->forget($importRunId);
    }
}
