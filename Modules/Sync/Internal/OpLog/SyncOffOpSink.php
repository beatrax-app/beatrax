<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Psr\Log\LoggerInterface;

// The one sink allowed to lose a mutation, and it loses nothing: a device with
// no identity key-file has never synced, so there is no peer to owe. Deferring
// here would fill a table on every install that only ever runs on one machine,
// and switching sync on backfills the whole database anyway.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
final readonly class SyncOffOpSink implements OpCaptureSink
{
    public function __construct(private LoggerInterface $log) {}

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

    // Debug, not error: an install that never enabled sync raises this on
    // every write it makes, and at error level that was 120k lines in one real
    // log — burying the failures the level is for.
    private function skipped(string $table, int|string $pk): void
    {
        $this->log->debug('SyncOffOpSink: sync is not enabled on this device; nothing captured.', [
            'table' => $table,
            'pk' => (string) $pk,
        ]);
    }
}
