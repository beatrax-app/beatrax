<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\StrandedEncryptionEpochException;
use Modules\Ledger\Public\Services\CounterpartyKeyBackfill;
use Modules\Sync\Public\Services\EncryptionMigrationSupport;
use Throwable;

class EncryptionMigrationService
{
    private const CHUNK_SIZE = 500;

    private const PROGRESS_CACHE_PREFIX = 'encryption-migration-progress:';

    private const PROGRESS_TTL_SECONDS = 3600;

    public function __construct(
        protected readonly DatabaseManager $db,
        private readonly PreMigrationSnapshot $snapshot,
        private readonly AppLockKeyService $appLockKeyService,
        private readonly Clock $clock,
        private readonly Container $container,
        private readonly CacheRepository $cache,
    ) {}

    // Idempotent no-op once `current_epoch` is set.
    public function migrate(User $user, Session $session): void
    {
        $userId = $user->id;
        $connection = $this->db->connection();

        $state = $this->loadState($connection, $userId);
        $currentEpochId = $this->currentEpochId($state);

        // This method needs the raw KEK only as the snapshot passphrase; GDK
        // epoch key material never reaches this class — Sync owns that.
        $kek = $this->appLockKeyService->release($session);

        try {
            if ($currentEpochId !== null) {
                // `current_epoch` set does not prove the keyring holds a usable
                // key: a crash in the commit-then-finalize window strands it and
                // silently disables sensitive writes. When locked, defer instead
                // of raising a false alarm.
                if ($kek !== null) {
                    /** @var EncryptionMigrationSupport $support */
                    $support = $this->container->make(EncryptionMigrationSupport::class);
                    if (! $support->hasUsableCurrentEpoch($userId, $session)) {
                        throw new StrandedEncryptionEpochException(
                            "Encryption is recorded as enabled for user {$userId} "
                            ."(current_epoch={$currentEpochId}) but the GDK keyring holds no key "
                            .'for that epoch — a stranded post-commit finalize state. The '
                            .'keyring file must be finalized/restored before sensitive writes resume.',
                        );
                    }

                    $this->backfillCounterpartyKeysOnce($connection, $userId, $state, $support, $session);
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

    // Lets callers read the flag without reaching into Modules\Sync\Internal.
    public function isEnabled(int $userId): bool
    {
        return $this->currentEpochId($this->loadState($this->db->connection(), $userId)) !== null;
    }

    // 0-99 while a pass runs (cache-backed, so no new synced table), 100 once
    // sync_encryption_state confirms the commit.
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

        // Snapshot the plaintext before the transaction opens — before the GDK
        // epoch exists and before any row is touched.
        $snapshotPath = $this->snapshot->takeSnapshot($userId, $connection, $kek);

        // Built outside the closure so the SAME instance is reachable inside it
        // (stage + encrypt) and after it returns (finalize the staged keyring,
        // only once the SQL transaction has actually committed).
        /** @var EncryptionMigrationSupport $support */
        $support = $this->container->make(EncryptionMigrationSupport::class);

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

                // Last, and inside the same transaction: it rewrites
                // `fingerprint` alongside the key it is derived from, and a
                // half-swept table would re-import as a second ledger.
                $this->backfillCounterpartyKeys($connection, $userId, $support->stagedBlindIndexKeyHex());

                $this->finalizeMigration($connection, $userId);
            });
        } catch (Throwable $e) {
            // The rollback already reverted every DB write, `current_epoch`
            // included. Reached only for genuine rollbacks, never post-commit —
            // restoring plaintext after a commit would corrupt the epoch.
            $support->discardStagedEpoch();
            $this->snapshot->restoreFromSnapshot($snapshotPath, $kek, $connection);
            $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 0, self::PROGRESS_TTL_SECONDS);

            throw $e;
        }

        // Past the commit: a failure here must NOT restore plaintext, which
        // would leave a committed epoch sitting over plaintext rows. Finalize
        // the staged keyring instead; a re-entry via migrate() can recover.
        try {
            $support->finalizeStagedEpoch();
        } catch (Throwable $e) {
            $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 0, self::PROGRESS_TTL_SECONDS);

            throw new StrandedEncryptionEpochException(
                "Keyring finalize failed after commit for user {$userId}: `current_epoch` is "
                .'committed but the keyring file is not yet in place. The staged key file was '
                .'preserved for retry — re-run migrate() to reconcile. Plaintext was NOT restored '
                .'(that would corrupt the committed epoch).',
                0,
                $e,
            );
        }

        // The snapshot holds every sensitive value in the clear, wrapped only
        // under the migration-time KEK and never rewrapped, so it must not
        // survive on disk past a successful migration.
        @unlink($snapshotPath);

        $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 100, self::PROGRESS_TTL_SECONDS);
    }

    // Extension seam: a test subclass throws here after N rows to prove the
    // whole-pass rollback leaves zero half-encrypted rows. No-op in production.
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
                        // `op_log_entries.pk` is VARCHAR — always a string on read.
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
        $columns = PreMigrationSnapshot::PROJECTION_COLUMNS[$table] ?? [];
        if ($columns === []) {
            return;
        }

        $connection->table($table)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $table, $columns, $support, $total, &$processed): void {
                foreach ($rows as $row) {
                    $updates = $this->projectionUpdatesForRow($support, $table, $columns, $row);

                    if ($updates !== []) {
                        $connection->table($table)->where('id', $row->id)->update($updates);
                    }

                    $processed++;
                    $this->reportProgress($userId, $processed, $total);
                }

                $this->afterChunkProcessed($userId, $processed);
            }, 'id');
    }

    // A value that already AEAD-verifies under the current epoch is ciphertext
    // from a retried pass; skipping it stops a re-run double-encrypting.
    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function projectionUpdatesForRow(EncryptionMigrationSupport $support, string $table, array $columns, \stdClass $row): array
    {
        $updates = [];

        foreach ($columns as $column) {
            /** @var mixed $value */
            $value = $row->{$column} ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($support->alreadyEncryptedProjectionValue($table, $column, $value)) {
                continue;
            }

            $updates[$column] = $support->encryptProjectionValue($table, $column, $value);
        }

        return $updates;
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

    // runMigration() never re-enters once `current_epoch` is set, so a user
    // who enabled encryption before the counterparty key existed would keep
    // plaintext matching keys forever. This is the pass that converts them,
    // on the first unlocked visit to any screen that calls migrate().
    private function backfillCounterpartyKeysOnce(
        ConnectionInterface $connection,
        int $userId,
        ?\stdClass $state,
        EncryptionMigrationSupport $support,
        Session $session,
    ): void {
        if ($state !== null && ($state->counterparty_key_backfilled_at ?? null) !== null) {
            return;
        }

        $keyHex = $support->ensureBlindIndexKey($userId, $session);

        $connection->transaction(function () use ($connection, $userId, $keyHex): void {
            $this->backfillCounterpartyKeys($connection, $userId, $keyHex);
        });
    }

    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function backfillCounterpartyKeys(ConnectionInterface $connection, int $userId, string $blindIndexKeyHex): void
    {
        /** @var CounterpartyKeyBackfill $backfill */
        $backfill = $this->container->make(CounterpartyKeyBackfill::class);
        $backfill->run($userId, $blindIndexKeyHex);

        $connection->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update(['counterparty_key_backfilled_at' => $this->clock->now()]);
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
}
