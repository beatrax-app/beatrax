<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Queue\QueueRowLoader;

function queueRowLoaderSeedPending(string $queue = 'default'): int
{
    return (int) DB::table('jobs')->insertGetId([
        'queue' => $queue,
        'payload' => '{"data":{"command":"O:8:\"stdClass\":0:{}"}}',
        'attempts' => 2,
        'reserved_at' => null,
        'available_at' => CarbonImmutable::now()->getTimestamp(),
        'created_at' => CarbonImmutable::now()->getTimestamp(),
    ]);
}

it('load(pending) maps jobs rows into the QueueRow shape', function (): void {
    $id = queueRowLoaderSeedPending('imports');

    /** @var QueueRowLoader $loader */
    $loader = app(QueueRowLoader::class);
    $rows = $loader->load('pending');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['key'])->toBe((string) $id);
    expect($rows[0]['queue'])->toBe('imports');
    expect($rows[0]['attempts'])->toBe(2);
    expect($rows[0]['payload'])->toContain('command');
});

it('load(failed) maps failed_jobs rows with uuid key and parsed failed_at', function (): void {
    $uuid = 'uuid-loader-failed-1';
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"attempts":3}',
        'exception' => 'RuntimeException: boom',
        'failed_at' => CarbonImmutable::parse('2026-07-31 12:00:00'),
    ]);

    /** @var QueueRowLoader $loader */
    $loader = app(QueueRowLoader::class);
    $rows = $loader->load('failed');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['key'])->toBe($uuid);
    expect($rows[0]['uuid'])->toBe($uuid);
    expect($rows[0]['failedAt'])->toBeInstanceOf(CarbonImmutable::class);
});

it('load(batches) maps job_batches rows with counts and options blob', function (): void {
    $batchId = 'batch-loader-1';
    DB::table('job_batches')->insert([
        'id' => $batchId,
        'name' => 'nightly-sweep',
        'total_jobs' => 5,
        'pending_jobs' => 4,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode([]),
        // Laravel's DatabaseBatchRepository writes this column with serialize().
        'options' => serialize(['x' => 1]),
        'cancelled_at' => null,
        'created_at' => CarbonImmutable::now()->getTimestamp(),
        'finished_at' => null,
    ]);

    /** @var QueueRowLoader $loader */
    $loader = app(QueueRowLoader::class);
    $rows = $loader->load('batches');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['key'])->toBe($batchId);
    expect($rows[0]['name'])->toBe('nightly-sweep');
    expect($rows[0]['pendingJobs'])->toBe(4);
    expect($rows[0]['failedJobs'])->toBe(1);
    expect($rows[0]['options'])->toBe(serialize(['x' => 1]));
});

it('load(unknown-tab) falls through to the pending mapping', function (): void {
    queueRowLoaderSeedPending();

    /** @var QueueRowLoader $loader */
    $loader = app(QueueRowLoader::class);
    $rows = $loader->load('not-a-real-tab');

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toHaveKey('attempts');
});

// Three jobs in three different states rendered identically, and the date
// column read created_at — so a job that does not run until next week said
// "1 second ago" and "Delete job" was offered on a row a worker had reserved.
it('load(pending) carries reserved_at and available_at through to the row', function (): void {
    $now = CarbonImmutable::now();

    $availableId = (int) DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now->getTimestamp(),
        'created_at' => $now->getTimestamp(),
    ]);
    $reservedId = (int) DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => $now->getTimestamp(),
        'available_at' => $now->getTimestamp(),
        'created_at' => $now->getTimestamp(),
    ]);
    $delayedId = (int) DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now->addHours(168)->getTimestamp(),
        'created_at' => $now->getTimestamp(),
    ]);

    /** @var QueueRowLoader $loader */
    $loader = app(QueueRowLoader::class);
    $rows = collect($loader->load('pending'))->keyBy('key');

    expect($rows[(string) $availableId]['reservedAt'])->toBeNull();
    expect($rows[(string) $availableId]['availableAt'])->toBe($now->getTimestamp());

    expect($rows[(string) $reservedId]['reservedAt'])->toBe($now->getTimestamp());

    expect($rows[(string) $delayedId]['reservedAt'])->toBeNull();
    expect($rows[(string) $delayedId]['availableAt'])->toBe($now->addHours(168)->getTimestamp());
});

it('tells the three pending states apart on the page and withholds delete from a reserved row', function (): void {
    $user = User::query()->create([
        'username' => 'queue-states',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
    $now = CarbonImmutable::now();

    DB::table('jobs')->insert([
        [
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => $now->getTimestamp(), 'created_at' => $now->getTimestamp(),
        ],
        [
            'queue' => 'default', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => $now->getTimestamp(), 'available_at' => $now->getTimestamp(), 'created_at' => $now->getTimestamp(),
        ],
        [
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => $now->addHours(168)->getTimestamp(), 'created_at' => $now->getTimestamp(),
        ],
    ]);

    $html = (string) $this->actingAs($user)->get('/dev/queue/pending')->getContent();

    expect($html)->toContain('Available');
    expect($html)->toContain('Reserved');
    expect($html)->toContain('Scheduled');
    // One row is mid-execution, so two of the three rows may be deleted.
    expect(substr_count($html, 'data-testid="row-delete-button"'))->toBe(2);
    expect($html)->toContain('Worker running');
});
