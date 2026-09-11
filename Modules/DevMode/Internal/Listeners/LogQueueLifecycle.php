<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;

// The database driver and Horizon both delete successful rows from `jobs`, so
// /dev/queue can have no Completed tab. The log is the only surviving trace.
final readonly class LogQueueLifecycle
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function processed(JobProcessed $event): void
    {
        $this->logger->info(
            'queue.processed',
            $this->context($event->job, $event->connectionName),
        );
    }

    // $event->exception is whatever the job threw, which is as broad as
    // catch (Throwable): a QueryException's message is the statement WITH its
    // bindings. The channel tap cannot stand in for this — it matches
    // credential shapes, and a counterparty name is not one.
    public function failed(JobFailed $event): void
    {
        $exception = $event->exception;

        $this->logger->warning('queue.failed', [
            ...$this->context($event->job, $event->connectionName),
            ...SafeExceptionContext::describe($exception),
            ...SafeExceptionContext::refusedCell($exception),
            // The only messages that survive. A class implements this to
            // promise its message names the shape of the failure and never a
            // value read out of a row.
            'message' => $exception instanceof MessageNamesNoUserData ? $exception->getMessage() : null,
        ]);
    }

    /**
     * @return array<string, scalar>
     */
    private function context(Job $job, string $connectionName): array
    {
        return [
            'job' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $connectionName,
            'attempts' => $job->attempts(),
            'uuid' => $job->uuid() ?? '',
        ];
    }
}
