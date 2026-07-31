<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\DevMode\Internal\Queue\QueueRowLoader;

/*
 * QueueRowLoader — per-tab row reader extracted off QueueInspectorPage.
 *
 * Covers:
 *   - load('pending') maps jobs rows to the QueueRow shape (int id
 *     coerced to a string key, queue/attempts/payload carried).
 *   - load('failed') maps failed_jobs rows (uuid becomes both key and
 *     uuid; failed_at parsed to a CarbonImmutable).
 *   - load('batches') maps job_batches rows (name/pending/failed
 *     counts + options blob).
 *   - An unknown tab falls through to the pending mapping.
 */

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
        'options' => json_encode(['x' => 1]),
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
    expect($rows[0]['options'])->toBe(json_encode(['x' => 1]));
});

it('load(unknown-tab) falls through to the pending mapping', function (): void {
    queueRowLoaderSeedPending();

    /** @var QueueRowLoader $loader */
    $loader = app(QueueRowLoader::class);
    $rows = $loader->load('not-a-real-tab');

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toHaveKey('attempts');
});
