<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Queue;

use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Public\Contracts\AuditWriter;

/**
 * Queue actions service — programmatic interface for the Dev Console
 * queue inspector page. Every action writes one dev_mode_audit row
 * via the injected AuditWriter so the operator audit trail captures
 * every destructive (and re-dispatch) write.
 *
 * Constructor DI per the project's DI-only rule:
 *   - FailedJobProviderInterface  — Laravel's queue-failed seam.
 *                                   Bound by the framework's queue
 *                                   service provider; the default
 *                                   binding for a database queue is
 *                                   {@see DatabaseFailedJobProvider}.
 *                                   Used for forget / find / all + the
 *                                   payload accessor needed for retry.
 *   - BatchRepository             — direct DI for cancel/delete batch
 *                                   ops; never the Bus facade.
 *   - QueueFactory                — needed by retryFailed to re-push
 *                                   the original payload via
 *                                   connection($name)->pushRaw($payload, $queue).
 *   - DatabaseManager             — direct row-level access for the
 *                                   pending jobs table (no model
 *                                   write surface; the framework
 *                                   provider does not expose
 *                                   deletePending).
 *   - AuditWriter                 — recordDestructiveQueueAction
 *                                   wraps every call.
 *   - CurrentUser                 — the caller identity for the
 *                                   audit's causer_id field.
 *
 * Every action method writes ONE audit row via AuditEvent enum
 * values — never a free-form string.
 *
 * Triple-gate enforcement: the QueueInspectorPage Livewire layer
 * dispatches `triple-gate:open` BEFORE invoking bulkDelete and the
 * confirmed-event listener inside the page re-validates all three
 * gates before delegating to this class. The action class itself
 * is a thin, testable seam — it trusts the caller to have validated
 * the gates.
 */
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

    /**
     * Forget a single failed-job row (drop it without re-dispatching).
     * Writes an audit row with action = queue.failed.forget.
     */
    public function forgetFailed(string $uuid): void
    {
        $this->failed->forget($uuid);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueFailedForget->value,
            ['uuid' => $uuid],
            $this->callerId(),
        );
    }

    /**
     * Re-dispatch a single failed job: look up the failed_jobs row by
     * uuid, push the payload back onto the original connection +
     * queue, then forget the failed_jobs row. Writes
     * action = queue.failed.retry.
     *
     * Mirrors {@see RetryCommand::retryJob}
     * but injects via Queue::connection(...) so the call respects the
     * project's no-facade rule.
     */
    public function retryFailed(string $uuid): void
    {
        $job = $this->failed->find($uuid);

        if ($job === null) {
            // Idempotent — a row that vanished between list and click
            // is not an error worth surfacing; the audit row reflects
            // the intent.
            $this->audit->recordDestructiveQueueAction(
                AuditEvent::QueueFailedRetry->value,
                ['uuid' => $uuid, 'missing' => true],
                $this->callerId(),
            );

            return;
        }

        // FailedJobProviderInterface::find() returns `object|null` with
        // dynamic properties (id/uuid/connection/queue/payload/etc.);
        // readObjectStringProp() reads them via get_object_vars() so
        // larastan-strict-rules doesn't flag the dynamic-name access.
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

    /**
     * Delete a single pending `jobs` row by id. Writes
     * action = queue.pending.delete.
     */
    public function deletePending(int $id): void
    {
        $this->db->connection()->table('jobs')->where('id', $id)->delete();

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueuePendingDelete->value,
            ['id' => $id],
            $this->callerId(),
        );
    }

    /**
     * Cancel a batch (sets cancelled_at on the row). Writes
     * action = queue.batch.cancel. No-ops when the batch id is
     * unknown.
     */
    public function cancelBatch(string $batchId): void
    {
        $this->batches->cancel($batchId);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBatchCancel->value,
            ['batch_id' => $batchId],
            $this->callerId(),
        );
    }

    /**
     * Delete a batch row from job_batches. Writes
     * action = queue.batch.delete.
     */
    public function deleteBatch(string $batchId): void
    {
        $this->batches->delete($batchId);

        $this->audit->recordDestructiveQueueAction(
            AuditEvent::QueueBatchDelete->value,
            ['batch_id' => $batchId],
            $this->callerId(),
        );
    }

    /**
     * Retry every failed job that belongs to the batch by uuid.
     * The framework's Batch class does not expose a "retry-failures"
     * method; instead, we read the persisted `failed_job_ids` JSON
     * (uuids of the batch members that landed in failed_jobs) and
     * loop retryFailed. Writes action = queue.batch.retry-failures.
     */
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

        // Batch::$failedJobIds is a list of uuids; mirror the
        // framework's `queue:retry-batch` semantics.
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
     * Bulk retry every uuid in the list. Non-destructive — the
     * Livewire layer surfaces a single-confirm modal before calling.
     *
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
     * Bulk delete N rows from the matching table. Destructive — the
     * Livewire layer routes the call through the TripleGateModal
     * before invoking this method.
     *
     * `$kind` is one of: pending | failed | batches.
     *
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

    /**
     * Resolve the audit's `causer_id`. The CurrentUser contract throws
     * when no user is bound; the queue inspector page sits behind the
     * `ensureDeveloperMode` middleware so a caller always exists, but
     * keep a sentinel fallback (0) so the audit row still writes
     * cleanly when the action runs from a background context (e.g.
     * a console command that exercises QueueActions directly).
     */
    private function callerId(): int
    {
        try {
            return $this->currentUser->id();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Read a string property off a dynamically-typed `object`
     * (FailedJobProviderInterface::find returns `object|null`). Falls
     * back to the supplied default when the property is missing or
     * not a string.
     *
     * Uses get_object_vars() rather than `$object->{$name}` so
     * larastan-strict-rules does NOT flag the dynamic property name
     * read (property.dynamicName).
     */
    private function readObjectStringProp(object $object, string $name, string $default): string
    {
        $vars = get_object_vars($object);
        $value = $vars[$name] ?? null;

        return is_string($value) ? $value : $default;
    }
}
