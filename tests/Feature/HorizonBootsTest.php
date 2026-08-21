<?php

declare(strict_types=1);

use Laravel\Horizon\Horizon;
use Predis\Client as PredisClient;

// The missing-Redis case is an explicit skip predicate, never a
// swallow-on-throw around the body: swallowing turned "the container is not
// running" into a silent pass. A CI grep gate reads this file's literal text
// and trips if that pattern reappears.
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
