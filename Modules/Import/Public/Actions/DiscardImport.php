<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\ImportAlreadyConfirmedException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Sync\Public\Events\EntityMutated;

// Discarding a confirmed run would orphan the ledger rows it created. The
// wizard hides Discard after confirm, but this action is Public and
// reachable programmatically, so the guard lives here.
final readonly class DiscardImport
{
    public function __construct(
        private PreviewCache $cache,
        private Dispatcher $events,
    ) {}

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

        // `status` is merged, and the pairing backfill carries every run
        // whatever its status — so a peer holding this one went on offering to
        // resume a preview the reader had thrown away. A Set naming a run the
        // peer never received updates nothing, which is right for a local run.
        $this->events->dispatch(new EntityMutated(
            table: 'import_runs',
            pk: $importRunId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['status' => ImportRunStatus::Discarded->value],
        ));

        $this->cache->forget($importRunId);
    }
}
