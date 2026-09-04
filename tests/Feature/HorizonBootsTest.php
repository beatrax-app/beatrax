<?php

declare(strict_types=1);

use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Laravel\Horizon\Horizon;
use Predis\Client as PredisClient;

// The missing-Redis case is an explicit skip predicate, never a
// swallow-on-throw around the body: swallowing turned "the container is not
// running" into a silent pass. Nothing enforces that but the reader — this note
// used to claim a CI grep gate read the file's literal text and tripped if the
// pattern came back, and no such gate has ever existed in the tree.
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

// This used to skip unless the process was started with QUEUE_CONNECTION=redis,
// which phpunit.xml pins to `sync` for the whole suite — so the one assertion it
// carried had never run anywhere. Neither half of it needs that environment.
it('takes its queue default from QUEUE_CONNECTION, and redis resolves to the driver Horizon needs', function (): void {
    // config/queue.php falls back to `database` when the variable is absent, so
    // reading the environment's own value back off the config is the wiring
    // itself: a literal in that file reads the same in one environment and
    // wrong in every other.
    expect(config('queue.default'))->toBe(env('QUEUE_CONNECTION', 'database'));

    // The rest needs no reachable server. RedisConnector hands RedisQueue the
    // connection factory and nothing dials out until a job is pushed, so
    // whether the name Horizon requires resolves to a redis-driven queue is
    // answerable in a suite whose queue is sync.
    config()->set('queue.default', 'redis');

    expect(app(QueueManager::class)->connection())->toBeInstanceOf(RedisQueue::class);
});
