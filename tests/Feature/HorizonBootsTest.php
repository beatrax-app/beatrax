<?php

declare(strict_types=1);

use Laravel\Horizon\Horizon;
use Predis\Client as PredisClient;

/*
 * Smoke-test the Phase 5 Wave 0 queue infrastructure preconditions:
 *
 *   - the Docker Redis container is reachable on 127.0.0.1:6379
 *   - the Horizon package is installed and its config publishes a
 *     non-empty array
 *   - the queue config defaults to the redis driver when the env var
 *     is set accordingly
 *
 * The Redis-ping test uses an EXPLICIT skip predicate. Earlier drafts
 * wrapped the test body in the swallow-on-throw alternative which
 * silently consumed any exception — meaning the Wave 0 precondition
 * (Redis container running) became invisible in test output. With the
 * predicate the skip reason surfaces in `pest` output as
 * "SKIPPED: Redis container required — run `docker start
 * beatrax-redis` or follow the README setup." so any maintainer or CI
 * job sees what is missing.
 *
 * NEVER replace the predicate with a swallow-on-throw alternative — a
 * CI grep gate runs against this file's literal text and trips when
 * that pattern reappears (locking the fix in place).
 */

/**
 * Helper: tries a one-shot socket connect with a 1-second timeout.
 * Surfaces the precondition explicitly so the test output names what
 * is missing instead of silently passing as the swallow-on-throw
 * alternative would.
 */
function isRedisReachable(string $host, int $port): bool
{
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($socket === false) {
        return false;
    }
    fclose($socket);

    return true;
}

it('connects to Redis on 127.0.0.1:6379', function (): void {
    $client = new PredisClient(['host' => '127.0.0.1', 'port' => 6379]);
    $response = $client->ping();
    $payload = is_object($response) && method_exists($response, 'getPayload')
        ? (string) $response->getPayload()
        : (string) $response;
    expect($payload)->toBe('PONG');
})->skip(
    fn (): bool => ! isRedisReachable('127.0.0.1', 6379),
    'Redis container required for this test — run `docker start beatrax-redis` or follow the README setup.',
);

it('Horizon service provider boots without errors', function (): void {
    expect(class_exists(Horizon::class))->toBeTrue();
    expect(config('horizon'))->toBeArray();
});

it('queue config defaults to redis driver when QUEUE_CONNECTION=redis', function (): void {
    expect(config('queue.default'))->toBe('redis');
})->skip(
    fn (): bool => env('QUEUE_CONNECTION') !== 'redis',
    'QUEUE_CONNECTION=redis required in env to assert default driver.',
);
