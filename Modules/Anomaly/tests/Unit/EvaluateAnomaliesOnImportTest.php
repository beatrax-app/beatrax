<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Anomaly\Public\Contracts\DispatchesAnomalyDetection;

// Anomaly evaluation used to be dispatched from TransactionImported, one job
// per row, with a unique key that was also per-row — so it deduplicated
// nothing. A 3,300-row import left 3,300 queued jobs on the device, measured at
// 3.05s each: about two and a half hours of background work for one file.
it('queues one job for the whole import run, not one per row', function (): void {
    Bus::fake();

    app(DispatchesAnomalyDetection::class)->dispatchForImportRun(userId: 1, importRunId: 9);

    Bus::assertDispatchedTimes(DetectAnomaliesJob::class, 1);
    Bus::assertDispatched(
        DetectAnomaliesJob::class,
        static fn (DetectAnomaliesJob $job): bool => $job->userId === 1 && $job->importRunId === 9,
    );
});

// The key is what makes a repeat dispatch collapse. Per transaction it could
// never collide across an import; per run it does.
it('keys the job on the run, so a second dispatch for it collapses', function (): void {
    $first = new DetectAnomaliesJob(userId: 3, importRunId: 11);
    $second = new DetectAnomaliesJob(userId: 3, importRunId: 11);
    $otherRun = new DetectAnomaliesJob(userId: 3, importRunId: 12);

    expect($first->uniqueId())->toBe($second->uniqueId())
        ->and($first->uniqueId())->not->toBe($otherRun->uniqueId());
});

// The event that used to drive this carried one transaction, so nothing could
// batch. Nothing may listen to it for anomalies again.
it('has no listener left on the per-transaction import event', function (): void {
    expect(class_exists('Modules\Anomaly\Internal\Listeners\EvaluateAnomaliesOnTransactionImport'))
        ->toBeFalse('a per-row listener is what produced one job per transaction.');
});
