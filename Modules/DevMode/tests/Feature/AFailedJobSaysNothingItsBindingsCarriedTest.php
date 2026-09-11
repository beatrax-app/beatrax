<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\DevMode\Internal\Listeners\LogQueueLifecycle;
use Modules\DevMode\Tests\Support\FakeJob;
use Modules\DevMode\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;

// LogQueueLifecycle is registered outside the dev_mode conditional, so it runs
// on every install including the documented Docker one — where LOG_CHANNEL is
// stderr and the log is whatever `docker compose logs` shows anybody who can
// reach the host.

/**
 * The counterparty and the IBAN a failing insert carries as its bindings.
 *
 * @return array{string, string}
 */
function aFailedJobsBoundRow(): array
{
    return ['Dr A. Specialist', 'NL91ABNA0417164300'];
}

function aFailedJobsQueryException(): QueryException
{
    [$counterparty, $iban] = aFailedJobsBoundRow();

    return new QueryException(
        'sqlite',
        'insert into "counterparties" ("name", "iban") values (?, ?)',
        [$counterparty, $iban],
        new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed'),
    );
}

it('writes no statement and no binding when a job fails on a query', function (): void {
    [$counterparty, $iban] = aFailedJobsBoundRow();
    $logger = new RecordingLogger;
    $this->app->instance(LoggerInterface::class, $logger);

    $this->app->make(Dispatcher::class)->dispatch(new JobFailed(
        'database',
        new FakeJob(name: 'Modules\\Ingestion\\Jobs\\ParseCamtFile', queue: 'imports', attempts: 3, uuid: 'job-uuid-1'),
        aFailedJobsQueryException(),
    ));

    expect($logger->records)->toHaveCount(
        1,
        'The listener is wired in DevModeServiceProvider, so a JobFailed reaching no logger means this test measured nothing at all.',
    );

    $encoded = json_encode($logger->records[0], JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain($counterparty)
        ->and($encoded)->not->toContain($iban)
        ->and($encoded)->not->toContain('insert into')
        ->and($encoded)->not->toContain('UNIQUE constraint failed');

    // The positive control. A listener that refused everything would drop
    // these too, and the four assertions above would still pass.
    expect($logger->records[0]['message'])->toBe('queue.failed')
        ->and($logger->records[0]['context'])->toMatchArray([
            'job' => 'Modules\\Ingestion\\Jobs\\ParseCamtFile',
            'queue' => 'imports',
            'uuid' => 'job-uuid-1',
            'reason' => QueryException::class,
        ])
        ->and($logger->records[0]['context'])->toHaveKey('sqlstate');
});

it('keeps the message of a class that promised it names no row', function (): void {
    $logger = new RecordingLogger;
    $listener = new LogQueueLifecycle($logger);

    $promised = new class('this CSV does not match the ASN layout') extends RuntimeException implements MessageNamesNoUserData {};

    $listener->failed(new JobFailed('database', new FakeJob, $promised));
    $listener->failed(new JobFailed('database', new FakeJob, aFailedJobsQueryException()));

    expect($logger->records[0]['context']['message'])->toBe('this CSV does not match the ASN layout')
        ->and($logger->records[1]['context']['message'])->toBeNull();
});

it('redacts a credential on the stderr channel the Docker deployment names', function (): void {
    // Assembled rather than spelled: a literal of this shape fails the secret
    // gate on every other open pull request, for content their diff never had.
    $token = 'ya29.'.str_repeat('aB3dE7', 6);
    $stream = tempnam(sys_get_temp_dir(), 'stderr-redaction-').'.log';

    config(['logging.channels.stderr.handler_with.stream' => $stream]);

    /** @var LogManager $manager */
    $manager = $this->app->make(LogManager::class);
    $manager->forgetChannel('stderr');
    $channel = $manager->channel('stderr');

    $channel->warning('queue.failed', ['detail' => 'refresh failed for '.$token]);

    // close() is what flushes the handler's buffer to the stream.
    foreach ($channel->getLogger()->getHandlers() as $handler) {
        $handler->close();
    }

    $contents = (string) file_get_contents($stream);
    @unlink($stream);

    // The positive control: the channel wrote the line at all. Without it a
    // redactor that blanked every record, or a stream nothing reached, reads
    // exactly like a channel that redacted the token.
    expect($contents)->toContain('queue.failed')
        ->and($contents)->toContain('refresh failed for')
        ->and($contents)->toContain('[REDACTED]')
        ->and($contents)->not->toContain($token);
});
