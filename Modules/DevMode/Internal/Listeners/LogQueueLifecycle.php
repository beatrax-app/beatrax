<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Psr\Log\LoggerInterface;

// Both the database queue driver and Horizon delete successful rows
// from `jobs` on completion, so /dev/queue has no Completed tab — this
// listener writes `queue.processed` (INFO) / `queue.failed` (WARNING) to
// the Laravel log instead, filterable via the tailer's `contains` field.
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

    public function failed(JobFailed $event): void
    {
        $context = $this->context($event->job, $event->connectionName);
        $context['exception'] = $event->exception->getMessage();

        $this->logger->warning('queue.failed', $context);
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
