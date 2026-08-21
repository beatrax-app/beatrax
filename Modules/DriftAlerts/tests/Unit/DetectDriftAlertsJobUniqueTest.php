<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;

// No RefreshDatabase: every assertion is reflection or a method call on a
// freshly-instantiated job.

it('declares ShouldBeUniqueUntilProcessing and ShouldQueue', function (): void {
    $reflection = new ReflectionClass(DetectDriftAlertsJob::class);
    expect($reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class))->toBeTrue();
    expect($reflection->implementsInterface(ShouldQueue::class))->toBeTrue();
});

it('uniqueId is a "{userId}:{seriesId}" composite string', function (): void {
    $job = new DetectDriftAlertsJob(userId: 42, recurringSeriesId: 7);
    expect($job->uniqueId())->toBe('42:7');
});

it('uniqueFor returns 600 seconds (the documented lock window)', function (): void {
    $job = new DetectDriftAlertsJob(userId: 1, recurringSeriesId: 1);
    expect($job->uniqueFor())->toBe(600);
});

it('uniqueVia returns a Cache Repository resolved through the LockStore helper', function (): void {
    $job = new DetectDriftAlertsJob(userId: 1, recurringSeriesId: 1);
    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
});

it('tries=3 and backoff=[60,300,900]', function (): void {
    $job = new DetectDriftAlertsJob(userId: 1, recurringSeriesId: 1);
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300, 900]);
});
