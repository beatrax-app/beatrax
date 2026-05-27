<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Modules\DevMode\Internal\Listeners\LogQueueLifecycle;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/*
 * LogQueueLifecycle surfaces queue lifecycle to the Laravel log so
 * /dev/logs shows completed and failed jobs (the database driver +
 * Horizon both delete successful rows from the `jobs` table, so the
 * queue inspector cannot show them).
 *
 * Coverage:
 *
 *   1. JobProcessed emits `queue.processed` at INFO with the stable
 *      context keys (job, queue, connection, attempts, uuid).
 *   2. JobFailed emits `queue.failed` at WARNING with the same keys
 *      plus a stringified `exception` message.
 *   3. The DevModeServiceProvider wires both events to the listener,
 *      so dispatching a real Job{Processed,Failed} event through the
 *      container's Dispatcher produces a log record.
 */

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class FakeJob implements Job
{
    public function __construct(
        private readonly string $name = 'App\\Jobs\\ExampleJob',
        private readonly string $queue = 'default',
        private readonly int $attempts = 1,
        private readonly ?string $uuid = 'fake-uuid-1',
    ) {}

    public function uuid()
    {
        return $this->uuid;
    }

    public function getJobId()
    {
        return '99';
    }

    public function getRawBody()
    {
        return '{}';
    }

    public function fire()
    {
        // no-op
    }

    public function payload()
    {
        return [];
    }

    public function resolveQueuedJobClass()
    {
        return $this->name;
    }

    public function release($delay = 0)
    {
        // no-op
    }

    public function isReleased()
    {
        return false;
    }

    public function isDeleted()
    {
        return false;
    }

    public function delete()
    {
        // no-op
    }

    public function isDeletedOrReleased()
    {
        return false;
    }

    public function attempts()
    {
        return $this->attempts;
    }

    public function hasFailed()
    {
        return false;
    }

    public function markAsFailed()
    {
        // no-op
    }

    public function fail($e = null)
    {
        // no-op
    }

    public function maxTries()
    {
        return null;
    }

    public function maxExceptions()
    {
        return null;
    }

    public function backoff()
    {
        return null;
    }

    public function retryUntil()
    {
        return null;
    }

    public function timeout()
    {
        return null;
    }

    public function getName()
    {
        return $this->name;
    }

    public function resolveName()
    {
        return $this->name;
    }

    public function getConnectionName()
    {
        return 'database';
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function getRawConnection()
    {
        return null;
    }
}

it('emits queue.processed at INFO with stable context keys on JobProcessed', function (): void {
    $logger = new RecordingLogger;
    $listener = new LogQueueLifecycle($logger);

    $listener->processed(new JobProcessed('database', new FakeJob(
        name: 'Modules\\Ingestion\\Jobs\\ParseCamtFile',
        queue: 'imports',
        attempts: 2,
        uuid: 'job-uuid-abc',
    )));

    expect($logger->records)->toHaveCount(1);
    $record = $logger->records[0];
    expect($record['level'])->toBe('info');
    expect($record['message'])->toBe('queue.processed');
    expect($record['context'])->toMatchArray([
        'job' => 'Modules\\Ingestion\\Jobs\\ParseCamtFile',
        'queue' => 'imports',
        'connection' => 'database',
        'attempts' => 2,
        'uuid' => 'job-uuid-abc',
    ]);
});

it('emits queue.failed at WARNING with exception message on JobFailed', function (): void {
    $logger = new RecordingLogger;
    $listener = new LogQueueLifecycle($logger);

    $listener->failed(new JobFailed(
        'database',
        new FakeJob(name: 'App\\Jobs\\Boom', queue: 'default', attempts: 5, uuid: 'boom-1'),
        new RuntimeException('catastrophic failure'),
    ));

    expect($logger->records)->toHaveCount(1);
    $record = $logger->records[0];
    expect($record['level'])->toBe('warning');
    expect($record['message'])->toBe('queue.failed');
    expect($record['context'])->toMatchArray([
        'job' => 'App\\Jobs\\Boom',
        'queue' => 'default',
        'connection' => 'database',
        'attempts' => 5,
        'uuid' => 'boom-1',
        'exception' => 'catastrophic failure',
    ]);
});

it('is wired in DevModeServiceProvider so dispatched JobProcessed events reach the listener', function (): void {
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new JobProcessed('database', new FakeJob));

    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toBe('queue.processed');
});

it('is wired in DevModeServiceProvider so dispatched JobFailed events reach the listener', function (): void {
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new JobFailed('database', new FakeJob, new RuntimeException('boom')));

    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toBe('queue.failed');
    expect($logger->records[0]['context']['exception'])->toBe('boom');
});
