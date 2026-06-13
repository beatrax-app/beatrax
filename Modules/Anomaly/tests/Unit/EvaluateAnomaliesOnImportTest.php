<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Anomaly\Internal\Listeners\EvaluateAnomaliesOnTransactionImport;
use Modules\Core\Models\User;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Transaction;

/*
 * Confirms the EvaluateAnomaliesOnTransactionImport listener QUEUES
 * exactly one DetectAnomaliesJob per inbound TransactionImported event,
 * carrying the event's user id + transaction id verbatim — and never
 * runs the evaluator inline (T-09-14: detection must not slow the
 * synchronous import transaction).
 *
 * No DB needed: the event carries unsaved-but-id-stamped models and
 * Bus::fake() captures the dispatch without booting a queue worker.
 */

it('queues exactly one DetectAnomaliesJob per inbound TransactionImported event', function (): void {
    Bus::fake();

    /** @var EvaluateAnomaliesOnTransactionImport $listener */
    $listener = $this->app->make(EvaluateAnomaliesOnTransactionImport::class);

    $transaction = new Transaction;
    $transaction->id = 77;
    $user = new User;
    $user->id = 42;

    $listener->handle(new TransactionImported($transaction, $user));

    Bus::assertDispatchedTimes(DetectAnomaliesJob::class, 1);
    Bus::assertDispatched(
        DetectAnomaliesJob::class,
        fn (DetectAnomaliesJob $job): bool => $job->userId === 42 && $job->transactionId === 77,
    );
});

it('does NOT invoke the evaluator synchronously in the listener (queues only)', function (): void {
    // The listener source must contain no AnomalyEvaluator reference at
    // all — detection happens only inside the queued job. A grep-style
    // guard mirrored as an assertion so the contract is test-enforced.
    $source = file_get_contents(
        base_path('Modules/Anomaly/Internal/Listeners/EvaluateAnomaliesOnTransactionImport.php'),
    );

    expect($source)->not->toContain('AnomalyEvaluator');
    // It must read the event's model property, not a flat ->transactionId.
    expect($source)->toContain('$event->transaction->id');
    // No `$event->transactionId` access in the CODE (the docblock may
    // mention it as a warning). Strip comment lines, then assert.
    $codeOnly = preg_replace('/^\s*(\*|\/\/).*$/m', '', $source) ?? $source;
    expect($codeOnly)->not->toContain('$event->transactionId');
});
