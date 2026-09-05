<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Modules\DevMode\Internal\Listeners\LogQueueLifecycle;
use Modules\DevMode\Tests\Support\FakeJob;
use Modules\DevMode\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;

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
