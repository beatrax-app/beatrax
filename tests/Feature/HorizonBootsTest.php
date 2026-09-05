<?php

declare(strict_types=1);

use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Laravel\Horizon\Horizon;

// A test that pinged a live Redis on 127.0.0.1:6379 used to open this file. No
// job runs one, so it skipped in every report it appeared in while being
// counted as a test — and the server is out of scope by decision: Horizon is a
// dev-only dependency of the Dev Console and the shipped queue is `database`.

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
