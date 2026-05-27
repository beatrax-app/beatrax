<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Psr\Log\LoggerInterface;

/**
 * Surfaces queue lifecycle into the Laravel log so /dev/logs shows
 * "what just ran" / "what just failed". The Laravel database driver
 * and Horizon both delete successful jobs from the `jobs` table on
 * completion, so /dev/queue has no Completed tab — this listener is
 * the visibility seam.
 *
 * Two structured log messages:
 *
 *   - `queue.processed` at INFO    (success path)
 *   - `queue.failed`    at WARNING (retries exhausted)
 *
 * Filter the tailer's `contains` field by either string to slice the
 * stream to queue activity. Context keys are stable (`job`, `queue`,
 * `connection`, `attempts`, `uuid`) so a future enrichment surface
 * can lift them without reparsing the message body.
 */
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
