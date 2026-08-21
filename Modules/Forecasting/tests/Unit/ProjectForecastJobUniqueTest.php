<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;

// The unique lock is plain string equality on the key, which is why null and a
// literal scenario id of 0 must not both render as "0".

it('declares ShouldBeUniqueUntilProcessing and ShouldQueue', function (): void {
    $reflection = new ReflectionClass(ProjectForecastJob::class);
    expect($reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class))->toBeTrue();
    expect($reflection->implementsInterface(ShouldQueue::class))->toBeTrue();
});

it('uniqueId is "{userId}:baseline:{horizonDays}" for a null scenario', function (): void {
    $job = new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 30);
    expect($job->uniqueId())->toBe('5:baseline:30');
});

it('uniqueId is "{userId}:{scenarioId}:{horizonDays}" for a literal scenario id', function (): void {
    $job = new ProjectForecastJob(userId: 5, scenarioId: 0, horizonDays: 30);
    expect($job->uniqueId())->toBe('5:0:30');
});

it('disambiguates null-scenarioId from literal scenario id 0 (the collision-safety guard)', function (): void {
    $baseline = new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 30);
    $literalZero = new ProjectForecastJob(userId: 5, scenarioId: 0, horizonDays: 30);

    expect($baseline->uniqueId())->not->toBe($literalZero->uniqueId());
    expect($baseline->uniqueId())->toBe('5:baseline:30');
    expect($literalZero->uniqueId())->toBe('5:0:30');
});

it('collapses two literal (5, null, 30) dispatches under the same uniqueId', function (): void {
    $a = new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 30);
    $b = new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 30);

    expect($a->uniqueId())->toBe($b->uniqueId());
});

it('throws InvalidArgumentException when horizonDays is not in the allowed set', function (): void {
    $call = fn (): ProjectForecastJob => new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 45);

    expect($call)->toThrow(InvalidArgumentException::class);
});

it('accepts horizonDays=180 without throwing', function (): void {
    $job = new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 180);
    expect($job->horizonDays)->toBe(180);
});

it('accepts horizonDays=365 without throwing', function (): void {
    $job = new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 365);
    expect($job->horizonDays)->toBe(365);
});

it('still throws InvalidArgumentException for an off-list horizonDays=200', function (): void {
    $call = fn (): ProjectForecastJob => new ProjectForecastJob(userId: 5, scenarioId: null, horizonDays: 200);

    expect($call)->toThrow(InvalidArgumentException::class);
});

it('uniqueFor returns 600 seconds', function (): void {
    $job = new ProjectForecastJob(userId: 1, scenarioId: null, horizonDays: 30);
    expect($job->uniqueFor())->toBe(600);
});

it('uniqueVia returns a Cache Repository resolved through the LockStore helper', function (): void {
    $job = new ProjectForecastJob(userId: 1, scenarioId: null, horizonDays: 30);
    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
});

it('tries=3 and backoff=[60, 300, 900]', function (): void {
    $job = new ProjectForecastJob(userId: 1, scenarioId: null, horizonDays: 30);
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300, 900]);
});

it('Bus::fake captures the dispatched job carrying userId / scenarioId / horizonDays', function (): void {
    Bus::fake();

    ProjectForecastJob::dispatch(7, null, 60);

    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $job): bool => $job->userId === 7
            && $job->scenarioId === null
            && $job->horizonDays === 60);
});
