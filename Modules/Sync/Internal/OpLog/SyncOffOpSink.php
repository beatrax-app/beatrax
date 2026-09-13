<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Psr\Log\LoggerInterface;

// The one sink allowed to lose a mutation, and it loses nothing: a reader with
// neither an identity key-file nor a registered self row has never synced, so
// there is no peer to owe, and enabling it backfills their whole database. A
// restored self row falsifies that, and the standing enum is what says so.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
final readonly class SyncOffOpSink implements OpCaptureSink
{
    public function __construct(private LoggerInterface $log, private int $userId) {}

    public function writeSet(string $table, int|string $pk, string $field, mixed $value): void
    {
        $this->skipped($table, $pk);
    }

    public function writeIncrement(string $table, int|string $pk, string $field, int $delta): void
    {
        $this->skipped($table, $pk);
    }

    public function writeCreateRow(string $table, int|string $pk, array $fields): void
    {
        $this->skipped($table, $pk);
    }

    public function writeDelete(string $table, int|string $pk): void
    {
        $this->skipped($table, $pk);
    }

    // Debug, not error: a reader who never enabled sync raises this on every
    // write they make, and at error level that was 120k lines in one real log.
    /**
     * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md#the-standing-is-the-readers-not-the-installs
     */
    private function skipped(string $table, int|string $pk): void
    {
        $this->log->debug('SyncOffOpSink: sync is not enabled for this reader; nothing captured.', [
            'user_id' => $this->userId,
            'table' => $table,
            'pk' => (string) $pk,
        ]);
    }
}
