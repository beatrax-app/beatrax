<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Core\Public\Support\LockStore;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;

uses()->group('Phase14');

it('resolves the database store when cache.locks_store is database', function (): void {
    config(['cache.locks_store' => 'database']);

    $repository = LockStore::forUniqueJobs();

    expect($repository)->toBeInstanceOf(Repository::class)
        ->and($repository->getStore())->toBeInstanceOf(DatabaseStore::class);
});

it('resolves the redis store when cache.locks_store is redis', function (): void {
    config(['cache.locks_store' => 'redis']);

    $repository = LockStore::forUniqueJobs();

    expect($repository)->toBeInstanceOf(Repository::class)
        ->and($repository->getStore())->toBeInstanceOf(RedisStore::class);
});

it('resolves the configured store rather than a hard-coded one', function (): void {
    config(['cache.locks_store' => 'array']);

    expect(LockStore::forUniqueJobs()->getStore())->toBeInstanceOf(ArrayStore::class);

    config(['cache.locks_store' => 'database']);

    expect(LockStore::forUniqueJobs()->getStore())->toBeInstanceOf(DatabaseStore::class);
});

it('routes ResolveChainLinksJob uniqueVia to the same store the helper resolves', function (): void {
    config(['cache.locks_store' => 'database']);

    $job = new ResolveChainLinksJob(1);

    expect($job->uniqueVia()->getStore())
        ->toBeInstanceOf(LockStore::forUniqueJobs()->getStore()::class);
});

it('routes every ShouldBeUnique job uniqueVia to the configured lock store', function (string $jobClass): void {
    config(['cache.locks_store' => 'database']);

    /** @var object $job */
    $job = (new ReflectionClass($jobClass))->newInstanceWithoutConstructor();

    /** @var Repository $resolved */
    $resolved = $job->uniqueVia();

    expect($resolved)->toBeInstanceOf(Repository::class)
        ->and($resolved->getStore())->toBeInstanceOf(DatabaseStore::class);
})->with([
    ResolveChainLinksJob::class,
    ScanInboxDropFolderJob::class,
    ProcessFetchedInboxMessagesJob::class,
    BackfillInboxJob::class,
    IncrementalScanJob::class,
    DiscoveryScanJob::class,
    DetectDriftAlertsJob::class,
    ProjectForecastJob::class,
    DetectRecurringSeriesJob::class,
]);
