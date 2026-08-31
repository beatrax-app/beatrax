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
    // rather than the Queue facade this project does not use. The bool is what
    // the batch and bulk callers count: counting the uuids they were handed
    // made a second click report the same number as the first.
    public function retryFailed(string $uuid): bool
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

            return false;
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

        return true;
    }

    // Returns the rows actually removed. A row that vanished between render and
    // click deleted nothing, and an audit row saying otherwise is a deletion
    // nobody can find in the table it names.
    public function deletePending(int $id): int
    {
        $deleted = $this->db->connection()->table('jobs')->where('id', $id)->delete();

        if ($deleted === 0) {
            return 0;
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueuePendingDelete->value,
            ['id' => $id],
            $this->callerId(),
        );

        return $deleted;
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
    public function retryBatchFailures(string $batchId): int
    {
        $batch = $this->batches->find($batchId);

        if ($batch === null) {
            $this->audit->recordDestructiveQueueAction(
                AuditEvent::QueueBatchRetryFailures->value,
                ['batch_id' => $batchId, 'missing' => true],
                $this->callerId(),
            );

            return 0;
        }

        $uuids = $batch->failedJobIds;
        $retried = 0;
        foreach ($uuids as $uuid) {
            if (! is_string($uuid)) {
                continue;
            }
            if ($this->retryFailed($uuid)) {
                $retried++;
            }
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBatchRetryFailures->value,
            ['batch_id' => $batchId, 'count' => $retried],
            $this->callerId(),
        );

        return $retried;
    }

    /**
     * @param  list<string>  $uuids
     */
    public function bulkRetry(array $uuids): int
    {
        $count = 0;
        foreach ($uuids as $uuid) {
            if ($uuid === '') {
                continue;
            }
            if ($this->retryFailed($uuid)) {
                $count++;
            }
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBulkRetry->value,
            ['count' => $count],
            $this->callerId(),
        );

        return $count;
    }

    // The count is affected rows, not selected ids: a selection of three that
    // found one row still on disk used to be audited as three deletions.
    /**
     * @param  list<int|string>  $ids
     */
    public function bulkDelete(array $ids, string $kind): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += $this->deleteOne($id, $kind);
        }

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBulkDelete->value,
            ['kind' => $kind, 'count' => $count],
            $this->callerId(),
        );

        return $count;
    }

    private function deleteOne(int|string $id, string $kind): int
    {
        return match ($kind) {
            'pending' => $this->deleteOnePending($id),
            'failed' => is_string($id) && $id !== '' && $this->failed->forget($id) === true ? 1 : 0,
            // BatchRepository::delete() reports nothing, so the row is looked
            // up first rather than assumed to have been there.
            'batches' => $this->deleteOneBatch($id),
            default => throw new InvalidArgumentException("Unknown bulk-delete kind '{$kind}'."),
        };
    }

    private function deleteOnePending(int|string $id): int
    {
        if (! is_int($id) && ! ctype_digit($id)) {
            return 0;
        }

        return $this->db->connection()->table('jobs')->where('id', (int) $id)->delete() > 0 ? 1 : 0;
    }

    private function deleteOneBatch(int|string $id): int
    {
        if (! is_string($id) || $id === '' || $this->batches->find($id) === null) {
            return 0;
        }

        $this->batches->delete($id);

        return 1;
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
