<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Core\Models\User;

// The payload shape this job had before it took an import run instead of a
// row, copied from the `jobs` table of a phone that upgraded across the
// change. 2,609 of these retried forever with `failed_jobs` at 0, because the
// fatal came from uniqueId() -- which the queue calls outside the handler's
// try/catch -- and every page load tried again.
function oldShapeAnomalyPayload(int $userId, int $transactionId): DetectAnomaliesJob
{
    $class = DetectAnomaliesJob::class;
    $serialized = sprintf(
        'O:%d:"%s":2:{s:6:"userId";i:%d;s:13:"transactionId";i:%d;}',
        strlen($class),
        $class,
        $userId,
        $transactionId,
    );

    $job = unserialize($serialized);
    expect($job)->toBeInstanceOf(DetectAnomaliesJob::class);

    return $job;
}

it('gives a queued old-shape payload a unique id instead of a fatal', function (): void {
    $job = oldShapeAnomalyPayload(1, 1046);

    expect($job->uniqueId())->toBe('1:tx1046');
});

it('handles an old-shape payload without the fatal that wedged the queue', function (): void {
    $user = User::query()->create([
        'username' => 'anomaly-old-payload',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $job = oldShapeAnomalyPayload($user->id, 4242);

    $raised = null;

    try {
        $job->handle(app(AnomalyEvaluator::class), app(DatabaseManager::class));
    } catch (Throwable $e) {
        $raised = $e;
    }

    // The regression was an Error, not an exception, raised before any work:
    // "Typed property ...::$importRunId must not be accessed before
    // initialization". Anything the evaluator itself does with a row that is
    // not there is a different question and not what this pins.
    expect($raised?->getMessage() ?? '')->not->toContain('importRunId');
    expect($raised)->not->toBeInstanceOf(Error::class);
});

it('still keys a current payload by its import run', function (): void {
    $job = new DetectAnomaliesJob(7, 99);

    expect($job->uniqueId())->toBe('7:99');
});
