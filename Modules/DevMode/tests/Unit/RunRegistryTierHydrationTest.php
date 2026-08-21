<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Process\RunRegistry;

function storeRawRun(string $runId, array $overrides): void
{
    /** @var CacheRepository $cache */
    $cache = app(CacheRepository::class);
    $cache->put('dev_mode.run.'.$runId, array_merge([
        'runId' => $runId,
        'pid' => 4242,
        'command' => 'cache:clear',
        'args' => [],
        'startedAt' => CarbonImmutable::parse('2026-05-24T10:00:00Z')->toIso8601String(),
        'callerUserId' => 7,
        'status' => 'running',
        'outPath' => '/tmp/'.$runId.'.out',
        'exitCode' => null,
        'finishedAt' => null,
    ], $overrides), 60);
}

it('hydrates a cached run with no tier key as the SAFE tier', function (): void {
    storeRawRun('no-tier-key', []);

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $record = $registry->find('no-tier-key');

    expect($record)->not->toBeNull();
    expect($record->tier)->toBe(CommandTier::Safe);
});

it('hydrates a cached run whose stored tier is unreadable as the SAFE tier', function (): void {
    storeRawRun('bogus-tier', ['tier' => 'not-a-tier']);
    storeRawRun('numeric-tier', ['tier' => 7]);

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    expect($registry->find('bogus-tier')->tier)->toBe(CommandTier::Safe);
    expect($registry->find('numeric-tier')->tier)->toBe(CommandTier::Safe);
});

it('round-trips a destructive tier through the cache without downgrading it', function (): void {
    storeRawRun('destructive-run', ['tier' => 'destructive']);

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $record = $registry->find('destructive-run');

    expect($record)->not->toBeNull();
    expect($record->tier)->toBe(CommandTier::Destructive);

    $registry->store($record);
    expect($registry->find('destructive-run')->tier)->toBe(CommandTier::Destructive);
});
