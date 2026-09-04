<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// Records where the mutation landed and throws the value away. The drain reads
// the column back from the live row and stamps the HLC then, so the op means
// "this device's current truth, announced late" — which is what makes a late
// announcement converge instead of overwriting a peer's newer write.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md#coordinates-not-values
 */
final readonly class DeferredOpCaptureSink implements OpCaptureSink
{
    public function __construct(
        private int $userId,
        private DeferredOpCaptures $queue,
    ) {}

    public function writeSet(string $table, int|string $pk, string $field, mixed $value): void
    {
        $this->queue->record($this->userId, $table, $pk, $field, DeferredOpKind::Set);
    }

    public function writeIncrement(string $table, int|string $pk, string $field, int $delta): void
    {
        $this->queue->record($this->userId, $table, $pk, $field, DeferredOpKind::Increment, $delta);
    }

    // One entry per named field rather than one per row: the caller chose this
    // column list — notifications adds its own digest id to it — and a drain
    // that re-read the whole row instead would put columns on the wire that no
    // live capture ever sends.
    public function writeCreateRow(string $table, int|string $pk, array $fields): void
    {
        /** @var string $field */
        foreach (array_keys($fields) as $field) {
            $this->queue->record($this->userId, $table, $pk, $field, DeferredOpKind::Create);
        }
    }

    public function writeDelete(string $table, int|string $pk): void
    {
        $this->queue->record($this->userId, $table, $pk, OpLogWriter::TOMBSTONE_FIELD, DeferredOpKind::Delete);
    }
}
