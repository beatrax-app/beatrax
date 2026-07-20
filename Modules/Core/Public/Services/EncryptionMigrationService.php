<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Services\EncryptionMigrationSupport;
use RuntimeException;
use Throwable;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
class EncryptionMigrationService
{
    // Mirrors the CHUNK_SIZE idiom already established by
    // RecordTransactions/ReapplyRulesJob.
    private const CHUNK_SIZE = 500;

    private const PROGRESS_CACHE_PREFIX = 'encryption-migration-progress:';

    private const PROGRESS_TTL_SECONDS = 3600;

    // See architecture.md for the tax_transaction_tags/transaction_splits
    // backfill rationale.
    private const PROJECTION_COLUMNS = [
        'transactions' => ['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload'],
        'counterparties' => ['display_name', 'merchant_name', 'iban'],
        'tax_transaction_tags' => ['note'],
        'transaction_splits' => ['note'],
    ];

    public function __construct(
        protected readonly DatabaseManager $db,
        private readonly BackupEncryptor $backupEncryptor,
        private readonly AppLockKeyService $appLockKeyService,
        private readonly Clock $clock,
        private readonly Container $container,
        private readonly CacheRepository $cache,
    ) {}

    // Idempotent no-op when encryption is already fully enabled
    // (`current_epoch` already set). See architecture.md for the
    // app-lock-locked and atomicity/rollback behavior.
    public function migrate(User $user, Session $session): void
    {
        $userId = $user->id;
        $connection = $this->db->connection();

        $state = $this->loadState($connection, $userId);
        $currentEpochId = $this->currentEpochId($state);

        // The raw KEK is only needed by this method for the BackupEncryptor
        // snapshot (mirrors GdkKeyringService's own "raw KEK bytes as the
        // passphrase" idiom); GDK epoch key material never reaches this
        // class at all — EncryptionMigrationSupport owns that.
        $kek = $this->appLockKeyService->release($session);

        try {
            if ($currentEpochId !== null) {
                // `current_epoch` set does NOT prove the keyring holds a
                // usable key — a crash in the commit-then-finalize window
                // can strand it, silently disabling sensitive writes. Verify
                // when unlocked; when locked, defer rather than false-alarm.
                if ($kek !== null) {
                    /** @var EncryptionMigrationSupport $support */
                    $support = $this->container->make(EncryptionMigrationSupport::class);
                    if (! $support->hasUsableCurrentEpoch($userId, $session)) {
                        throw new RuntimeException(
                            "Encryption is recorded as enabled for user {$userId} "
                            ."(current_epoch={$currentEpochId}) but the GDK keyring holds no key "
                            .'for that epoch — a stranded post-commit finalize state. The '
                            .'keyring file must be finalized/restored before sensitive writes resume.',
                        );
                    }
                }

                return;
            }

            if ($kek === null) {
                $this->clearStaleInProgressFlag($connection, $userId, $state);

                return;
            }

            $this->runMigration($userId, $session, $kek, $connection);
        } finally {
            if ($kek !== null) {
                sodium_memzero($kek);
            }
        }
    }

    // Read-only helper for callers (e.g. the Devices & Sync UI) that need
    // the boolean state without reaching into Modules\Sync\Internal\*.
    public function isEnabled(int $userId): bool
    {
        return $this->currentEpochId($this->loadState($this->db->connection(), $userId)) !== null;
    }

    // Resumable-idempotent progress signal for the UI to poll: 0-99 while a
    // pass is running (cache-backed, mirrors ReapplyRulesJob's precedent —
    // no new synced table), 100 once sync_encryption_state confirms commit.
    public function progress(int $userId): int
    {
        $state = $this->loadState($this->db->connection(), $userId);
        if ($this->currentEpochId($state) !== null && ! $this->isInProgress($state)) {
            return 100;
        }

        $cached = $this->cache->get(self::PROGRESS_CACHE_PREFIX.$userId);

        return is_int($cached) ? $cached : 0;
    }

    private function runMigration(int $userId, Session $session, string $kek, ConnectionInterface $connection): void
    {
        $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 0, self::PROGRESS_TTL_SECONDS);

        // Backup-first: snapshot the pre-migration plaintext BEFORE
        // the transaction even opens — before the GDK epoch exists, before
        // any row is touched.
        $snapshotPath = $this->takeSnapshot($userId, $connection, $kek);

        // $support is constructed OUTSIDE the transaction closure so the SAME
        // instance is reachable both inside (to stage + encrypt) and after it
        // returns (to finalize the staged keyring file, only once the SQL
        // transaction has actually committed).
        /** @var EncryptionMigrationSupport $support */
        $support = $this->container->make(EncryptionMigrationSupport::class);

        // First: the encrypt pass + `current_epoch` write, all inside one
        // SQL transaction. A throw HERE means the transaction rolled back every
        // DB write — plaintext is intact, no epoch was committed — so the
        // snapshot restore + staged-file discard are the correct response.
        try {
            $connection->transaction(function () use ($userId, $session, $connection, $support): void {
                $this->setMigrationInProgress($connection, $userId, true);

                // Stages (does not finalize) the epoch-1 keyring file;
                // `current_epoch` IS written here, inside this transaction.
                $support->stageFirstEpoch($userId, $session);

                $total = $this->estimateTotalRows($connection, $userId);
                $processed = 0;

                $this->encryptOpLogEntries($connection, $userId, $support, $total, $processed);
                $this->encryptProjectionTable($connection, $userId, 'transactions', $support, $total, $processed);
                $this->encryptProjectionTable($connection, $userId, 'counterparties', $support, $total, $processed);
                $this->encryptProjectionTable($connection, $userId, 'tax_transaction_tags', $support, $total, $processed);
                $this->encryptProjectionTable($connection, $userId, 'transaction_splits', $support, $total, $processed);

                $this->finalizeMigration($connection, $userId);
            });
        } catch (Throwable $e) {
            // In-transaction failure: transaction() already rolled back every
            // DB write (including `current_epoch`). Discard the un-finalized
            // staged `.tmp` and restore plaintext — only reached for genuine
            // rollbacks, never post-commit (which would corrupt the epoch).
            $support->discardStagedEpoch();
            $this->restoreFromSnapshot($snapshotPath, $kek, $connection);
            $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 0, self::PROGRESS_TTL_SECONDS);

            throw $e;
        }

        // Now: the SQL transaction has COMMITTED, so a failure here must NOT
        // restore plaintext (that would leave a committed epoch over
        // plaintext rows). Finalize the staged epoch-1 keyring file instead;
        // on failure a re-entry via migrate() can recover.
        try {
            $support->finalizeStagedEpoch();
        } catch (Throwable $e) {
            $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 0, self::PROGRESS_TTL_SECONDS);

            throw new RuntimeException(
                "Keyring finalize failed after commit for user {$userId}: `current_epoch` is "
                .'committed but the keyring file is not yet in place. The staged key file was '
                .'preserved for retry — re-run migrate() to reconcile. Plaintext was NOT restored '
                .'(that would corrupt the committed epoch).',
                0,
                $e,
            );
        }

        // On success the pre-migration plaintext snapshot (a full KEK-wrapped
        // copy of every sensitive value in the clear) is no longer needed —
        // delete it so it does not survive on disk indefinitely (it is
        // wrapped only under the migration-time KEK, not a later passphrase rewrap).
        @unlink($snapshotPath);

        $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 100, self::PROGRESS_TTL_SECONDS);
    }

    // Test-only extension seam, called once after each bounded chunk INSIDE
    // the outer migration transaction. A test subclass overrides this to
    // throw after a chosen number of rows, proving the whole-pass rollback
    // leaves zero half-encrypted rows. Production behavior is a no-op.
    protected function afterChunkProcessed(int $userId, int $rowsProcessedSoFar): void {}

    private function encryptOpLogEntries(
        ConnectionInterface $connection,
        int $userId,
        EncryptionMigrationSupport $support,
        int $total,
        int &$processed,
    ): void {
        $connection->table('op_log_entries')
            ->where('user_id', $userId)
            ->whereNull('gdk_epoch')
            ->whereNotNull('value')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $support, $total, &$processed): void {
                foreach ($rows as $row) {
                    /** @var mixed $rawTable */
                    $rawTable = $row->table_name;
                    /** @var mixed $rawField */
                    $rawField = $row->field;
                    /** @var mixed $rawValue */
                    $rawValue = $row->value;
                    if (! is_string($rawTable) || ! is_string($rawField) || ! is_string($rawValue)) {
                        $processed++;

                        continue;
                    }

                    if ($support->isSensitive($rawTable, $rawField)) {
                        // `op_log_entries.pk` is a VARCHAR column (always a
                        // string on read) — see the create-table migration.
                        /** @var mixed $rawPk */
                        $rawPk = $row->pk;
                        $pk = is_string($rawPk) ? $rawPk : '';

                        $encrypted = $support->encryptOpLogValue($rawTable, $pk, $rawField, $rawValue);

                        $connection->table('op_log_entries')
                            ->where('id', $row->id)
                            ->update([
                                'value' => $encrypted['value'],
                                'gdk_epoch' => $encrypted['epochId'],
                            ]);
                    }

                    $processed++;
                    $this->reportProgress($userId, $processed, $total);
                }

                $this->afterChunkProcessed($userId, $processed);
            }, 'id');
    }

    private function encryptProjectionTable(
        ConnectionInterface $connection,
        int $userId,
        string $table,
        EncryptionMigrationSupport $support,
        int $total,
        int &$processed,
    ): void {
        $columns = self::PROJECTION_COLUMNS[$table] ?? [];
        if ($columns === []) {
            return;
        }

        $connection->table($table)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $table, $columns, $support, $total, &$processed): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        /** @var mixed $value */
                        $value = $row->{$column} ?? null;
                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        // Row-level idempotency: a value that ALREADY
                        // AEAD-verifies under the current epoch is already
                        // ciphertext (a resumed/retried pass) — never
                        // double-encrypt it.
                        if ($support->alreadyEncryptedProjectionValue($table, $column, $value)) {
                            continue;
                        }

                        $updates[$column] = $support->encryptProjectionValue($table, $column, $value);
                    }

                    if ($updates !== []) {
                        $connection->table($table)->where('id', $row->id)->update($updates);
                    }

                    $processed++;
                    $this->reportProgress($userId, $processed, $total);
                }

                $this->afterChunkProcessed($userId, $processed);
            }, 'id');
    }

    private function estimateTotalRows(ConnectionInterface $connection, int $userId): int
    {
        $opLog = $connection->table('op_log_entries')
            ->where('user_id', $userId)
            ->whereNull('gdk_epoch')
            ->whereNotNull('value')
            ->count();

        $transactions = $connection->table('transactions')->where('user_id', $userId)->count();
        $counterparties = $connection->table('counterparties')->where('user_id', $userId)->count();
        $taxTags = $connection->table('tax_transaction_tags')->where('user_id', $userId)->count();
        $splits = $connection->table('transaction_splits')->where('user_id', $userId)->count();

        return max(1, $opLog + $transactions + $counterparties + $taxTags + $splits);
    }

    private function reportProgress(int $userId, int $processed, int $total): void
    {
        $percent = (int) min(99, floor(($processed / max(1, $total)) * 100));
        $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, $percent, self::PROGRESS_TTL_SECONDS);
    }

    private function setMigrationInProgress(ConnectionInterface $connection, int $userId, bool $inProgress): void
    {
        $now = $this->clock->now();
        $existing = $connection->table('sync_encryption_state')->where('user_id', $userId)->first(['id']);

        if ($existing === null) {
            $connection->table('sync_encryption_state')->insert([
                'user_id' => $userId,
                'current_epoch' => null,
                'migration_in_progress' => $inProgress,
                'enabled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $connection->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update([
                'migration_in_progress' => $inProgress,
                'updated_at' => $now,
            ]);
    }

    private function finalizeMigration(ConnectionInterface $connection, int $userId): void
    {
        $now = $this->clock->now();

        $connection->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update([
                'migration_in_progress' => false,
                'enabled_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function clearStaleInProgressFlag(ConnectionInterface $connection, int $userId, ?\stdClass $state): void
    {
        if ($state === null || ! $this->isInProgress($state)) {
            return;
        }

        $connection->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update([
                'migration_in_progress' => false,
                'updated_at' => $this->clock->now(),
            ]);
    }

    private function loadState(ConnectionInterface $connection, int $userId): ?\stdClass
    {
        /** @var \stdClass|null $row */
        $row = $connection->table('sync_encryption_state')->where('user_id', $userId)->first();

        return $row;
    }

    private function currentEpochId(?\stdClass $state): ?int
    {
        if ($state === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $state->current_epoch ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function isInProgress(?\stdClass $state): bool
    {
        if ($state === null) {
            return false;
        }

        /** @var mixed $value */
        $value = $state->migration_in_progress ?? false;

        return (bool) $value;
    }

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
        if (file_put_contents($tmpPlainPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to stage the pre-migration snapshot payload.');
        }
        if (! @chmod($tmpPlainPath, 0600)) {
            @unlink($tmpPlainPath);
            throw new RuntimeException('Cannot chmod the pre-migration snapshot payload to 0600.');
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
        $dir = dirname($snapshotPath);
        $tmpPlainPath = $dir.DIRECTORY_SEPARATOR.'beatrax_premig_restore_'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $this->backupEncryptor->decrypt($snapshotPath, $tmpPlainPath, $kek);
            $contents = file_get_contents($tmpPlainPath);
            $payload = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($tmpPlainPath);
        }

        if (! is_array($payload)) {
            // The snapshot itself could not be restored — the transaction
            // rollback already reverted every DB write this pass made, so
            // there is nothing left to repair. Do not throw: the ORIGINAL
            // failure is what the caller must see (re-thrown by the caller).
            return;
        }

        // Wrap every restore write in ONE transaction so the restore is
        // all-or-nothing — without it, a throw partway (DB error / one bad
        // row) would leave a partially-restored mixed state. This method is
        // only invoked on a genuine rollback, so it never contradicts a committed epoch.
        $connection->transaction(function () use ($connection, $payload): void {
            /** @var list<array<string, mixed>> $opLogRows */
            $opLogRows = is_array($payload['op_log_entries'] ?? null) ? $payload['op_log_entries'] : [];
            foreach ($opLogRows as $row) {
                if (! isset($row['id'])) {
                    continue;
                }
                $connection->table('op_log_entries')->where('id', $row['id'])->update([
                    'value' => $row['value'] ?? null,
                    'gdk_epoch' => null,
                ]);
            }

            foreach (['transactions', 'counterparties', 'tax_transaction_tags', 'transaction_splits'] as $table) {
                /** @var list<array<string, mixed>> $rows */
                $rows = is_array($payload[$table] ?? null) ? $payload[$table] : [];
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
        });
    }
}
