<?php

declare(strict_types=1);

use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Core\Public\Exceptions\LockStoreNotConfiguredException;
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

// The harness (tests/TestCase.php::setUp) remaps the `redis` cache store onto
// the `array` driver so no test opens a socket to a daemon. The assertions
// below therefore compare against Cache::store($name) rather than asserting a
// concrete driver class for the `redis` name.

it('resolves the database store when cache.locks_store is database', function (): void {
    config(['cache.locks_store' => 'database']);

    $repository = LockStore::forUniqueJobs();

    expect($repository)->toBeInstanceOf(Repository::class)
        ->and($repository->getStore())->toBeInstanceOf(DatabaseStore::class)
        ->and($repository->getStore())->toBe(Cache::store('database')->getStore());
});

it('resolves the store named redis (remapped to array under test) when cache.locks_store is redis', function (): void {
    config(['cache.locks_store' => 'redis']);

    $repository = LockStore::forUniqueJobs();

    expect($repository)->toBeInstanceOf(Repository::class)
        ->and($repository->getStore())->toBe(Cache::store('redis')->getStore());
});

it('resolves the configured store rather than a hard-coded one', function (): void {
    config(['cache.locks_store' => 'redis']);
    $redisStore = LockStore::forUniqueJobs()->getStore();

    config(['cache.locks_store' => 'database']);
    $databaseStore = LockStore::forUniqueJobs()->getStore();

    expect($databaseStore)->toBeInstanceOf(DatabaseStore::class)
        ->and($databaseStore)->not->toBe($redisStore)
        ->and($redisStore)->toBe(Cache::store('redis')->getStore());
});

it('routes ResolveChainLinksJob uniqueVia to the same store the helper resolves', function (): void {
    config(['cache.locks_store' => 'database']);

    $job = new ResolveChainLinksJob(1);

    expect($job->uniqueVia()->getStore())
        ->toBe(LockStore::forUniqueJobs()->getStore());
});

it('routes every ShouldBeUnique job uniqueVia to the configured lock store', function (string $jobClass): void {
    config(['cache.locks_store' => 'database']);

    /** @var object $job */
    $job = (new ReflectionClass($jobClass))->newInstanceWithoutConstructor();

    /** @var Repository $resolved */
    $resolved = $job->uniqueVia();

    expect($resolved)->toBeInstanceOf(Repository::class)
        ->and($resolved->getStore())->toBeInstanceOf(DatabaseStore::class)
        ->and($resolved->getStore())->toBe(LockStore::forUniqueJobs()->getStore());
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

// A value it cannot resolve a store from must throw rather than fall back to a
// default: every ShouldBeUnique job routes uniqueVia() through this helper, so
// each worker would take its lock in a store the others are not watching and
// two would scan the same inbox concurrently against the same rows.
it('refuses a lock store name it cannot use', function (mixed $configured): void {
    config(['cache.locks_store' => $configured]);

    expect(fn () => LockStore::forUniqueJobs())
        ->toThrow(LockStoreNotConfiguredException::class, 'cache.locks_store');
})->with([
    'unset' => [null],
    'empty string' => [''],
    'an array' => [['database']],
    'a boolean' => [false],
    'an integer' => [0],
]);

// The rejected value is echoed back because the failure is a configuration
// mistake someone has to find — naming the key without showing what it held
// leaves them looking at a value that reads fine in the file.
it('names the value it rejected', function (): void {
    config(['cache.locks_store' => '']);

    expect(fn () => LockStore::forUniqueJobs())
        ->toThrow(LockStoreNotConfiguredException::class, "''");
});
