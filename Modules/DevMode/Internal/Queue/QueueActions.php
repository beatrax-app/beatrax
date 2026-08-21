<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Queue;

use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Public\Contracts\AuditWriter;

final readonly class QueueActions
{
    public function __construct(
        private FailedJobProviderInterface $failed,
        private BatchRepository $batches,
        private QueueFactory $queue,
        private DatabaseManager $db,
        private AuditWriter $audit,
        private CurrentUser $currentUser,
    ) {}

    public function forgetFailed(string $uuid): void
    {
        $this->failed->forget($uuid);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueFailedForget->value,
            ['uuid' => $uuid],
            $this->callerId(),
        );
    }

    // Mirrors RetryCommand::retryJob, but through the injected QueueFactory
    // rather than the Queue facade this project does not use.
    public function retryFailed(string $uuid): void
    {
        $job = $this->failed->find($uuid);

        if ($job === null) {
            // A row that vanished between list and click is not an error;
            // the audit row still records the intent.
            $this->audit->recordDestructiveQueueAction(
                AuditEvent::QueueFailedRetry->value,
                ['uuid' => $uuid, 'missing' => true],
                $this->callerId(),
            );

            return;
        }

        $connection = $this->readObjectStringProp($job, 'connection', 'database');
        $queueName = $this->readObjectStringProp($job, 'queue', 'default');
        $payload = $this->readObjectStringProp($job, 'payload', '');

        if ($payload !== '') {
            // Reset attempts to 0 so the worker treats the re-push as a
            // fresh job rather than continuing the exhausted count.
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && isset($decoded['attempts'])) {
                $decoded['attempts'] = 0;
                $reencoded = json_encode($decoded);
                if (is_string($reencoded)) {
                    $payload = $reencoded;
                }
            }

            $this->queue->connection($connection)->pushRaw($payload, $queueName);
        }

        $this->failed->forget($uuid);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueFailedRetry->value,
            ['uuid' => $uuid, 'connection' => $connection, 'queue' => $queueName],
            $this->callerId(),
        );
    }

    public function deletePending(int $id): void
    {
        $this->db->connection()->table('jobs')->where('id', $id)->delete();

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueuePendingDelete->value,
            ['id' => $id],
            $this->callerId(),
        );
    }

    public function cancelBatch(string $batchId): void
    {
        $this->batches->cancel($batchId);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBatchCancel->value,
            ['batch_id' => $batchId],
            $this->callerId(),
        );
    }

    public function deleteBatch(string $batchId): void
    {
        $this->batches->delete($batchId);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBatchDelete->value,
            ['batch_id' => $batchId],
            $this->callerId(),
        );
    }

    // The framework's Batch class exposes no "retry-failures" method, so
    // this reads the persisted failed_job_ids JSON and loops retryFailed.
    public function retryBatchFailures(string $batchId): void
    {
        $batch = $this->batches->find($batchId);

        if ($batch === null) {
            $this->audit->recordDestructiveQueueAction(
                AuditEvent::QueueBatchRetryFailures->value,
                ['batch_id' => $batchId, 'missing' => true],
                $this->callerId(),
            );

            return;
        }

        $uuids = $batch->failedJobIds;
        $retried = [];
        foreach ($uuids as $uuid) {
            if (! is_string($uuid)) {
                continue;
            }
            $this->retryFailed($uuid);
            $retried[] = $uuid;
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBatchRetryFailures->value,
            ['batch_id' => $batchId, 'count' => count($retried)],
            $this->callerId(),
        );
    }

    /**
     * @param  list<string>  $uuids
     */
    public function bulkRetry(array $uuids): void
    {
        $count = 0;
        foreach ($uuids as $uuid) {
            if ($uuid === '') {
                continue;
            }
            $this->retryFailed($uuid);
            $count++;
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBulkRetry->value,
            ['count' => $count],
            $this->callerId(),
        );
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function bulkDelete(array $ids, string $kind): void
    {
        $count = 0;
        foreach ($ids as $id) {
            switch ($kind) {
                case 'pending':
                    if (is_int($id)) {
                        $this->db->connection()->table('jobs')->where('id', $id)->delete();
                        $count++;
                    } elseif (ctype_digit($id)) {
                        $this->db->connection()->table('jobs')->where('id', (int) $id)->delete();
                        $count++;
                    }
                    break;
                case 'failed':
                    if (is_string($id) && $id !== '') {
                        $this->failed->forget($id);
                        $count++;
                    }
                    break;
                case 'batches':
                    if (is_string($id) && $id !== '') {
                        $this->batches->delete($id);
                        $count++;
                    }
                    break;
                default:
                    throw new InvalidArgumentException("Unknown bulk-delete kind '{$kind}'.");
            }
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBulkDelete->value,
            ['kind' => $kind, 'count' => $count],
            $this->callerId(),
        );
    }

    // CurrentUser throws with no bound user, so 0 is the sentinel that lets a
    // console-triggered action still write its audit row.
    private function callerId(): int
    {
        try {
            return $this->currentUser->id();
        } catch (\Throwable) {
            return 0;
        }
    }

    // Uses get_object_vars() rather than $object->{$name} so
    // larastan-strict-rules doesn't flag the dynamic property name read.
    private function readObjectStringProp(object $object, string $name, string $default): string
    {
        $vars = get_object_vars($object);
        $value = $vars[$name] ?? null;

        return is_string($value) ? $value : $default;
    }
}
