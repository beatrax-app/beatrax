<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services\Concerns;

use Illuminate\Database\ConnectionInterface;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;

// The pre-migration plaintext snapshot and its restore: a targeted,
// KEK-wrapped copy of every sensitive column taken before the encrypt pass
// opens its transaction, plus the all-or-nothing replay that runs only on a
// genuine rollback. Split off EncryptionMigrationService as one concern.
/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
trait TakesPreMigrationSnapshot
{
    // See architecture.md for why this is a targeted sensitive-column
    // snapshot (mirrors GdkKeyringService's encrypted-file idiom) rather
    // than a whole-file SQLite copy.
    private function takeSnapshot(int $userId, ConnectionInterface $connection, string $kek): string
    {
        $payload = [
            'op_log_entries' => $connection->table('op_log_entries')
                ->where('user_id', $userId)
                ->whereNull('gdk_epoch')
                ->whereNotNull('value')
                ->get(['id', 'value'])
                ->map(static fn (object $row): array => ['id' => $row->id, 'value' => $row->value])
                ->all(),
            'transactions' => $this->captureRows($connection, 'transactions', $userId),
            'counterparties' => $this->captureRows($connection, 'counterparties', $userId),
            // These backfill-sweep tables must be in the pre-migration
            // snapshot too so a restore after a forced failure covers them.
            'tax_transaction_tags' => $this->captureRows($connection, 'tax_transaction_tags', $userId),
            'transaction_splits' => $this->captureRows($connection, 'transaction_splits', $userId),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $dir = UserDataPathService::appPath('sync/backups');
        @mkdir($dir, 0700, true);

        $tmpPlainPath = $dir.DIRECTORY_SEPARATOR.'beatrax_premig_'.bin2hex(random_bytes(8)).'.tmp';
        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($tmpPlainPath, $json, LOCK_EX) === false) {
            throw new BackupIoException('Failed to stage the pre-migration snapshot payload.');
        }
        if (! @chmod($tmpPlainPath, 0600)) {
            @unlink($tmpPlainPath);
            throw new BackupIoException('Cannot chmod the pre-migration snapshot payload to 0600.');
        }

        $encPath = UserDataPathService::appPath(
            sprintf('sync/backups/pre-encryption-%d-%s.enc', $userId, $this->clock->now()->format('YmdHis_u')),
        );

        try {
            $this->backupEncryptor->encrypt($tmpPlainPath, $encPath, $kek);
        } finally {
            @unlink($tmpPlainPath);
        }

        return $encPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function captureRows(ConnectionInterface $connection, string $table, int $userId): array
    {
        $columns = self::PROJECTION_COLUMNS[$table] ?? [];
        if ($columns === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->table($table)
            ->where('user_id', $userId)
            ->get(array_merge(['id'], $columns))
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return $rows;
    }

    private function restoreFromSnapshot(string $snapshotPath, string $kek, ConnectionInterface $connection): void
    {
        $payload = $this->decodeSnapshotPayload($snapshotPath, $kek);

        // The snapshot itself could not be restored — the transaction
        // rollback already reverted every DB write this pass made, so there
        // is nothing left to repair. Do not throw: the ORIGINAL failure is
        // what the caller must see (re-thrown by the caller).
        if ($payload === null) {
            return;
        }

        // Wrap every restore write in ONE transaction so the restore is
        // all-or-nothing — without it, a throw partway (DB error / one bad
        // row) would leave a partially-restored mixed state. This method is
        // only invoked on a genuine rollback, so it never contradicts a commit.
        $connection->transaction(function () use ($connection, $payload): void {
            /** @var list<array<string, mixed>> $opLogRows */
            $opLogRows = is_array($payload['op_log_entries'] ?? null) ? $payload['op_log_entries'] : [];
            $this->restoreOpLogRows($connection, $opLogRows);

            foreach (['transactions', 'counterparties', 'tax_transaction_tags', 'transaction_splits'] as $table) {
                /** @var list<array<string, mixed>> $rows */
                $rows = is_array($payload[$table] ?? null) ? $payload[$table] : [];
                $this->restoreProjectionRows($connection, $table, $rows);
            }
        });
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeSnapshotPayload(string $snapshotPath, string $kek): ?array
    {
        $dir = dirname($snapshotPath);
        $tmpPlainPath = $dir.DIRECTORY_SEPARATOR.'beatrax_premig_restore_'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $this->backupEncryptor->decrypt($snapshotPath, $tmpPlainPath, $kek);
            $contents = file_get_contents($tmpPlainPath);
            $payload = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($tmpPlainPath);
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function restoreOpLogRows(ConnectionInterface $connection, array $rows): void
    {
        foreach ($rows as $row) {
            if (! isset($row['id'])) {
                continue;
            }
            $connection->table('op_log_entries')->where('id', $row['id'])->update([
                'value' => $row['value'] ?? null,
                'gdk_epoch' => null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function restoreProjectionRows(ConnectionInterface $connection, string $table, array $rows): void
    {
        foreach ($rows as $row) {
            if (! isset($row['id'])) {
                continue;
            }
            $id = $row['id'];
            unset($row['id']);
            if ($row === []) {
                continue;
            }
            $connection->table($table)->where('id', $id)->update($row);
        }
    }
}
