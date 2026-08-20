<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Import\Public\Events\TransactionImported;

// Deliberately NOT a ShouldQueue listener: the job it dispatches is the queued
// half, and queueing both would defeat DetectAnomaliesJob's unique key.
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
