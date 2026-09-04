<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Core\Public\Support\LockStore;
use Tests\Contracts\Support\BackendSourceFiles;

// Laravel's UniqueLock reads uniqueVia() if the job declares one and falls back
// to the container's default cache otherwise. This app configures those as two
// different stores — cache.default is the file store, cache.locks_store the
// database one — so a job with no uniqueVia() takes its lock somewhere no other
// worker is watching, and "unique" stops meaning anything.

/**
 * @return list<class-string>
 */
function jobsAskingForUniqueness(): array
{
    $classes = [];

    foreach (BackendSourceFiles::all() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            continue;
        }
        if (preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $name) !== 1) {
            continue;
        }

        $class = $namespace[1].'\\'.$name[1];

        // A class the mobile root alone can autoload fatals on class_exists
        // rather than answering false, and it is never a queued job.
        try {
            if (! class_exists($class)) {
                continue;
            }
        } catch (Throwable) {
            continue;
        }

        if (! is_a($class, ShouldBeUnique::class, true) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

// The store, not the Repository: Cache::store() hands back a fresh wrapper each
// call, so comparing repositories reports a difference that is not one.
function lockStoreOf(string $class): ?object
{
    if (! method_exists($class, 'uniqueVia')) {
        return null;
    }

    $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $via = $job->uniqueVia();

    return $via instanceof Repository ? $via->getStore() : null;
}

it('takes every unique job lock in the store the app keeps locks in', function (): void {
    $jobs = jobsAskingForUniqueness();

    // A floor, because the failure a hand-written list cannot have is the one
    // this replaces: the walk finding nothing and reporting no offenders.
    expect($jobs)->toHaveCount(count($jobs))->and(count($jobs))->toBeGreaterThanOrEqual(20);

    $configured = LockStore::forUniqueJobs()->getStore();

    $offenders = [];
    foreach ($jobs as $job) {
        $store = lockStoreOf($job);

        if ($store === null) {
            $offenders[] = $job.' declares no uniqueVia(), so it locks in the container default';

            continue;
        }

        if ($store !== $configured) {
            $offenders[] = $job.' locks in '.$store::class.', not the configured lock store';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These jobs implement ShouldBeUnique and take their lock somewhere the',
        'other workers are not watching, so two of them run at once over the',
        'same rows:',
        ...$offenders,
        '',
        'Add: public function uniqueVia(): Repository { return LockStore::forUniqueJobs(); }',
    ]));
});

it('reports a unique job that never says where its lock lives', function (): void {
    // The suite points locks_store at the array store, which is also the
    // cheapest stand-in for a wrong one; naming database here keeps the two
    // apart so "not the configured store" is a real difference.
    config(['cache.locks_store' => 'database']);

    $withoutUniqueVia = new class implements ShouldBeUnique
    {
        public function uniqueId(): string
        {
            return 'planted';
        }
    };

    $intoTheWrongStore = new class implements ShouldBeUnique
    {
        public function uniqueVia(): Repository
        {
            return Cache::store('array');
        }
    };

    expect(lockStoreOf($withoutUniqueVia::class))->toBeNull()
        ->and(lockStoreOf($intoTheWrongStore::class))->not->toBe(LockStore::forUniqueJobs()->getStore());

    // The walk reads files, so an anonymous class cannot reach it — the pair
    // above pins the predicate, and this pins that the walk still finds jobs.
    expect(jobsAskingForUniqueness())->toContain(ReapplyRulesJob::class);
});
