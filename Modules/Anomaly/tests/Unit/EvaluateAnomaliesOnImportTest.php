<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Anomaly\Internal\Listeners\EvaluateAnomaliesOnTransactionImport;
use Modules\Core\Models\User;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Transaction;

// The listener must queue rather than evaluate inline: detection may not
// slow the synchronous import transaction.
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
    // Source-level guard: any AnomalyEvaluator reference in the listener
    // would mean detection can run inline.
    $source = file_get_contents(
        base_path('Modules/Anomaly/Internal/Listeners/EvaluateAnomaliesOnTransactionImport.php'),
    );

    expect($source)->not->toContain('AnomalyEvaluator');
    // It must read the event's model property, not a flat ->transactionId.
    expect($source)->toContain('$event->transaction->id');
    // Comments are stripped first so a comment warning against
    // `$event->transactionId` cannot itself fail the guard.
    $codeOnly = preg_replace('/^\s*(\*|\/\/).*$/m', '', $source) ?? $source;
    expect($codeOnly)->not->toContain('$event->transactionId');
});
