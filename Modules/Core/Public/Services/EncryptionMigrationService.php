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
 * D-09: the one-time, backup-first, atomic, rollback-on-failure migration
 * that turns EXISTING plaintext history into GDK ciphertext the moment
 * encryption is first enabled for a user (sync-enable OR single-device
 * opt-in). CRYPT-01 must hold for history retained forever, not just for
 * new writes going forward (those are already covered by the Plan 03
 * op-log write hook and the Plan 04 direct-write hook) — this service is
 * the ONE place that closes the gap for rows written BEFORE encryption
 * was ever turned on.
 *
 * ## Module boundary (`beatrax.boundary`, `app/PhpStan/Rules/BoundaryRule.php`)
 *
 * 14-06-PLAN.md's own `<interfaces>` block names `GdkKeyringService`,
 * `OpLogFieldCrypto`, and `SensitiveFieldRegistry` — all
 * `Modules\Sync\Internal\Crypto\*` — as this service's contract. The
 * project's custom PHPStan boundary rule forbids Core from importing
 * anything outside `Modules\Sync\Public\*`/`Modules\Sync\Models\*`
 * (CLAUDE.md: "Cross-module access goes through public service classes or
 * events; no module reaches into another's models or internals" — a hard
 * constraint that takes precedence over the plan's literal interfaces
 * text). `Modules\Sync\Public\Services\EncryptionMigrationSupport` (added
 * alongside this file, Rule 2 — missing critical functionality: the plan's
 * own interfaces were literally unbuildable without it) is the minimal
 * Public wrapper that closes the gap: every raw GDK key byte and the
 * `GdkEpoch` DTO itself stay fully inside Sync; this service only ever
 * sees plain integers (epoch ids) and ciphertext strings.
 *
 * ## Atomicity mechanism (Claude's Discretion per D-09)
 *
 * The WHOLE encrypt pass (GDK epoch generation + every op-log/projection
 * row write) runs inside ONE outer `DatabaseManager::transaction()` — a
 * real SQL transaction, not a resumable multi-transaction batch scheme.
 * A single SQLite transaction gives a strictly stronger, simpler
 * correctness guarantee than trying to restore a whole live SQLite file
 * mid-request (file-descriptor/WAL/lock hazards against the app's own open
 * PDO connection) would: on ANY throw inside the closure, Laravel rolls
 * back every row this pass touched — INCLUDING the `current_epoch`/
 * `migration_in_progress` writes — automatically, atomically, with no
 * half-encrypted state possible. Rows are still processed in bounded
 * `CHUNK_SIZE` batches (via `chunkById`) so memory stays bounded even
 * though the SQL transaction itself spans the whole pass — the "batches
 * over the forever-retained history" must-have is about processing
 * shape, not about splitting the atomicity boundary.
 *
 * On top of the transaction, a pre-migration `BackupEncryptor` snapshot
 * (D-09's literal "backup-first" requirement) is taken BEFORE the
 * transaction even opens — i.e. before the GDK epoch is even generated,
 * which is stricter than the plan's own prose order (generate-then-
 * snapshot) and is the most conservative reading of "no row is ever
 * touched before the safety net exists". On a forced failure, the
 * snapshot is explicitly decrypted and its captured pre-migration values
 * are written back — genuine defense-in-depth on top of (not instead of)
 * the transaction rollback, satisfying the plan's literal
 * "on ANY throw, restore from the pre-migration snapshot
 * (BackupEncryptor::decrypt) and re-throw" instruction independently.
 *
 * ## Row-level idempotency (resumable, never double-encrypts)
 *
 * `op_log_entries` rows are skipped once `gdk_epoch` is non-null (the
 * column IS the per-row "already encrypted" marker, exactly as the plan
 * suggests). Projection columns (`transactions`/`counterparties`) have no
 * such column, so each candidate value is first tried against
 * `EncryptionMigrationSupport::alreadyEncryptedProjectionValue()` — a real
 * AEAD verification under the current epoch + the exact AD it would have
 * been encrypted with, not a base64-pattern guess. A value that already
 * verifies is left untouched; only genuine plaintext is encrypted.
 *
 * ## App-lock-locked handling
 *
 * `migrate()` requires the app-lock KEK (`AppLockKeyService::release()`).
 * When it is unavailable, NO row is ever touched — this is not a D-09
 * "forced failure requiring rollback" (nothing was started), so `migrate()`
 * does not throw: it clears any stale `migration_in_progress` flag left by
 * a prior crashed attempt (so a locked app-lock can never leave a
 * confusing half-flagged state hanging around) and returns quietly. The
 * real "enable encryption" flow (Plan 09) already requires an unlocked
 * app-lock as a precondition (D-07), so this path is a defensive
 * degenerate case, not the primary UX.
 *
 * NOTE: intentionally NOT `final` — `EncryptionMigrationRollbackTest`'s
 * forced-failure proof (14-06-PLAN.md Task 2) subclasses this service to
 * override `afterChunkProcessed()`, mirroring
 * `Modules\Auth\Public\Services\AppLockKeyService`'s own "intentionally NOT
 * final... a thin seam subclassing could not violate any invariant"
 * precedent.
 */
class EncryptionMigrationService
{
    /**
     * Rows processed per bounded chunk — mirrors the CHUNK_SIZE idiom
     * already established by `RecordTransactions`/`ReapplyRulesJob`.
     */
    private const CHUNK_SIZE = 500;

    private const PROGRESS_CACHE_PREFIX = 'encryption-migration-progress:';

    private const PROGRESS_TTL_SECONDS = 3600;

    /**
     * D-02b sensitive projection columns, scoped to the tables THIS
     * migration's projection pass covers (transactions/counterparties —
     * 14-06-PLAN.md's own action text names only these two). Mirrors
     * `RecordTransactions`'s own established precedent of restating its
     * scoped column subset inline (its docblock enumerates the same 4 of
     * the 5 transactions columns) rather than reaching the Internal
     * `SensitiveFieldRegistry` for this table-scoped lookup — the op-log
     * pass below, which must cover every D-02b table (not just these two),
     * uses `EncryptionMigrationSupport::isSensitive()` (the Public
     * passthrough to the real registry) as ITS source of truth instead.
     *
     * `tax_transaction_tags.note`/`transaction_splits.note` are
     * DELIBERATELY excluded from this projection-column list: 14-04-SUMMARY
     * already flagged that neither has a write-side encrypt hook (both are
     * written via raw DB calls with no `SensitiveColumnCodec` hook), so a
     * one-time migration of their EXISTING rows would give a false sense of
     * completeness while every future write stays plaintext — that gap is
     * out of THIS plan's declared scope (Core service + rollback test
     * only) and stays flagged for a future plan/the phase-level review.
     */
    private const PROJECTION_COLUMNS = [
        'transactions' => ['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload'],
        'counterparties' => ['display_name', 'merchant_name', 'iban'],
    ];

    public function __construct(
        protected readonly DatabaseManager $db,
        private readonly BackupEncryptor $backupEncryptor,
        private readonly AppLockKeyService $appLockKeyService,
        private readonly Clock $clock,
        private readonly Container $container,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Migrate every existing plaintext row for $user to GDK ciphertext.
     * Idempotent no-op when encryption is already fully enabled
     * (`current_epoch` already set). See the class docblock for the
     * app-lock-locked and atomicity/rollback behavior.
     */
    public function migrate(User $user, Session $session): void
    {
        $userId = $user->id;
        $connection = $this->db->connection();

        $state = $this->loadState($connection, $userId);
        if ($this->currentEpochId($state) !== null) {
            // Encryption already enabled for this user on this device.
            return;
        }

        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            $this->clearStaleInProgressFlag($connection, $userId, $state);

            return;
        }
        // The raw KEK is only needed by this method for the BackupEncryptor
        // snapshot (mirrors GdkKeyringService's own "raw KEK bytes as the
        // passphrase" idiom); GDK epoch key material never reaches this
        // class at all — EncryptionMigrationSupport owns that.
        try {
            $this->runMigration($userId, $session, $kek, $connection);
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * Whether at-rest encryption is currently ON for $userId on this device
     * (`current_epoch` set). Read-only helper for callers (e.g. the Plan 09
     * Devices & Sync UI, the D-10 Change-PIN flash) that only need the
     * boolean state and must not reach into `Modules\Sync\Internal\*` to get
     * it — this Core Public service already owns the `sync_encryption_state`
     * read via {@see loadState()}/{@see currentEpochId()}.
     */
    public function isEnabled(int $userId): bool
    {
        return $this->currentEpochId($this->loadState($this->db->connection(), $userId)) !== null;
    }

    /**
     * Resumable-idempotent progress signal for the Plan 09 UI to poll:
     * 0-99 while a pass is running (cache-backed, mirrors
     * `ReapplyRulesJob`'s precedent — no new synced table), 100 once
     * `sync_encryption_state` itself confirms the pass committed.
     */
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

        // D-09 backup-first: snapshot the pre-migration plaintext BEFORE
        // the transaction even opens — before the GDK epoch exists, before
        // any row is touched.
        $snapshotPath = $this->takeSnapshot($userId, $connection, $kek);

        try {
            $connection->transaction(function () use ($userId, $session, $connection): void {
                $support = $this->container->make(EncryptionMigrationSupport::class);

                $this->setMigrationInProgress($connection, $userId, true);

                $support->enableFirstEpoch($userId, $session);

                $total = $this->estimateTotalRows($connection, $userId);
                $processed = 0;

                $this->encryptOpLogEntries($connection, $userId, $support, $total, $processed);
                $this->encryptProjectionTable($connection, $userId, 'transactions', $support, $total, $processed);
                $this->encryptProjectionTable($connection, $userId, 'counterparties', $support, $total, $processed);

                $this->finalizeMigration($connection, $userId);
            });

            $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 100, self::PROGRESS_TTL_SECONDS);
        } catch (Throwable $e) {
            // The transaction() call above already rolled back every DB
            // write. This explicit restore is defense-in-depth proving the
            // snapshot is independently a valid, restorable backup (D-09's
            // literal instruction) even though the SQL rollback is the
            // primary correctness guarantee.
            $this->restoreFromSnapshot($snapshotPath, $kek, $connection);
            $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, 0, self::PROGRESS_TTL_SECONDS);

            throw $e;
        }
    }

    /**
     * Test-only extension seam (14-06-PLAN.md Task 2's forced-failure
     * proof): called once after each bounded chunk of rows is processed,
     * INSIDE the outer migration transaction. A test subclass overrides
     * this to throw after a chosen number of rows, proving the whole-pass
     * rollback leaves zero half-encrypted rows — `$db` is `protected` (not
     * `private`) specifically so such a subclass can read
     * `sync_encryption_state` mid-transaction (same open PDO connection,
     * read-your-own-writes) to prove `migration_in_progress` was actually
     * observably true at the injected-failure point. Production behavior
     * is a no-op.
     */
    protected function afterChunkProcessed(int $userId, int $rowsProcessedSoFar): void
    {
        // no-op in production
    }

    // --- op_log_entries pass -------------------------------------------

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

    // --- transactions / counterparties projection-column pass -----------

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

        return max(1, $opLog + $transactions + $counterparties);
    }

    private function reportProgress(int $userId, int $processed, int $total): void
    {
        $percent = (int) min(99, floor(($processed / max(1, $total)) * 100));
        $this->cache->put(self::PROGRESS_CACHE_PREFIX.$userId, $percent, self::PROGRESS_TTL_SECONDS);
    }

    // --- sync_encryption_state bookkeeping -------------------------------

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

    // --- backup-first snapshot + restore ---------------------------------

    /**
     * Serialize every candidate row's CURRENT (pre-migration) sensitive
     * values into one JSON payload and encrypt it via `BackupEncryptor`
     * under the session KEK — mirrors `GdkKeyringService`'s own
     * encrypted-file idiom (raw KEK bytes as the "passphrase" argument,
     * staged plaintext locked to 0600 before it is ever encrypted, atomic
     * encrypt-to-real-path). This is a targeted sensitive-column snapshot
     * (the plan's "or the sensitive tables" alternative), not a whole-file
     * SQLite copy — safer than swapping the live database file out from
     * under the app's own open PDO connection mid-request.
     */
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
            // there is nothing left to repair. Do not throw here: the
            // ORIGINAL failure is what the caller must see (re-thrown by
            // runMigration()).
            return;
        }

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

        foreach (['transactions', 'counterparties'] as $table) {
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
    }
}
