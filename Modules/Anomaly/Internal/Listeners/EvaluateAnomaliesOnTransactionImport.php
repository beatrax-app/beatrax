<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Import\Public\Events\TransactionImported;

/**
 * Subscribes to the Import-side per-row TransactionImported event and
 * QUEUES a DetectAnomaliesJob for the affected transaction.
 *
 * The listener stays synchronous (no ShouldQueue on the listener itself
 * — the JOB is queued; double-queueing the listener would defeat the
 * unique-job key on (userId, transactionId)). It does NO baseline math
 * inline: detection runs on the queue inside DetectAnomaliesJob, off the
 * synchronous import DB transaction (D-12 / T-09-14). One inbound event →
 * exactly one DetectAnomaliesJob dispatch.
 *
 * INTERFACE NOTE: TransactionImported carries a Transaction model + User
 * (mirrors the Search IndexTransactionOnImport listener). The listener
 * reads $event->transaction->id + $event->user->id — NOT a flat
 * $event->transactionId, which does not exist on the event.
 *
 * Cross-module: imports Modules\Import\Public\Events only — never
 * Modules\Import\Internal. The crossModuleAccessGoesThroughPublic arch
 * invariant enforces this contract.
 */
final readonly class EvaluateAnomaliesOnTransactionImport
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(TransactionImported $event): void
    {
        $this->bus->dispatch(new DetectAnomaliesJob(
            userId: $event->user->id,
            transactionId: $event->transaction->id,
        ));
    }
}
