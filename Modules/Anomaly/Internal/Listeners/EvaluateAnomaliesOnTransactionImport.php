<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Import\Public\Events\TransactionImported;

// Stays synchronous (no ShouldQueue on the listener itself — the JOB is
// queued; double-queueing would defeat the unique-job key). Does NO
// baseline math inline. TransactionImported carries a Transaction model +
// User, not a flat transactionId.
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
