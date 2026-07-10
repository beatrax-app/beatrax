<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use LogicException;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkKeyringStage;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use RuntimeException;

/**
 * Sync Public surface consumed by
 * `Modules\Core\Public\Services\EncryptionMigrationService` (Phase 14 Plan
 * 06, D-09). The enable-time backup-first migration needs two Internal Sync
 * crypto capabilities no OTHER Public class exposes:
 *
 *   1. First-epoch generation (`GdkKeyringService::stageFirstEpoch()` /
 *      `finalizeStagedEpoch()` — split in two per D-10, see below).
 *   2. `op_log_entries.value` encryption under the EXACT per-entry AD
 *      `Modules\Sync\Internal\OpLog\OpLogWriter` itself binds
 *      (`"{table}:{pk}:{field}:{epochId}"`) — deliberately DIFFERENT from
 *      `SensitiveColumnCodec`'s pk-less projection AD, so it cannot be added
 *      to that class without conflating the two AD shapes it exists to keep
 *      distinct (see that class's own docblock).
 *
 * `beatrax.boundary` (the project's custom PHPStan cross-module rule, see
 * `app/PhpStan/Rules/BoundaryRule.php`) forbids Core from importing
 * `Modules\Sync\Internal\*` directly — this class is the minimal Public
 * wrapper that closes that gap while keeping every raw GDK key byte and the
 * `GdkEpoch` DTO itself fully inside the Sync module boundary. Callers
 * across the boundary only ever see plain integers (epoch ids) and
 * ciphertext strings.
 *
 * NOT a singleton (bound via `bind()`, mirrors `HybridLogicalClock`/
 * `SyncSession`'s "holds mutable state -> transient" precedent): an instance
 * caches the primed epoch's raw key material for the duration of ONE
 * migration pass so `encryptOpLogValue()`/`encryptProjectionValue()` never
 * re-derive the KEK or re-decrypt the keyring file per row (that I/O only
 * happens once, in `stageFirstEpoch()`/`primeCurrentEpoch()`).
 *
 * ## D-10 atomicity: staged epoch generation
 *
 * `stageFirstEpoch()` encrypts the epoch-1 keyring file to a `.tmp` sibling
 * and writes `sync_encryption_state.current_epoch` (participating in the
 * caller's ambient SQL transaction) but does NOT rename the file into
 * place. The caller MUST call `finalizeStagedEpoch()` after that
 * transaction commits (or `discardStagedEpoch()` if it rolls back) — this
 * closes the file-vs-DB atomicity window a prior version of this class
 * had, where the keyring file was finalized (renamed into place) INSIDE
 * the transaction, so a later chunk throw would roll back `current_epoch`
 * while the epoch-1 file persisted on disk (T-14.1-05).
 */
final class EncryptionMigrationSupport
{
    private ?int $cachedEpochId = null;

    private ?string $cachedEpochKeyHex = null;

    /**
     * D-10 atomicity: the pending stage from stageFirstEpoch(), passed back
     * to finalizeStagedEpoch()/discardStagedEpoch() by the caller.
     */
    private ?GdkKeyringStage $pendingStage = null;

    public function __construct(
        private readonly GdkKeyringService $keyringService,
        private readonly OpLogFieldCrypto $fieldCrypto,
        private readonly SensitiveFieldRegistry $registry,
    ) {}

    /**
     * Public passthrough for `SensitiveFieldRegistry::isSensitive()` so
     * Core never has to reach the Internal registry directly.
     */
    public function isSensitive(string $table, string $field): bool
    {
        return $this->registry->isSensitive($table, $field);
    }

    /**
     * D-10 atomicity fix: STAGE (do not finalize) GDK epoch 1 (group-of-one)
     * for $userId and prime this instance's cached epoch for the
     * encrypt*() calls that follow. The keyring FILE is not renamed into
     * place yet — the caller (`EncryptionMigrationService::runMigration()`)
     * MUST call {@see finalizeStagedEpoch()} once its surrounding SQL
     * transaction has committed, or {@see discardStagedEpoch()} if it
     * rolled back. Returns only the plain integer epoch id — never the raw
     * key bytes.
     *
     * @throws LogicException when the app-lock KEK is unavailable.
     */
    public function stageFirstEpoch(int $userId, Session $session): int
    {
        $stage = $this->keyringService->stageFirstEpoch($userId, $session);
        $this->pendingStage = $stage;
        $this->cachedEpochId = $stage->epoch->epochId;
        $this->cachedEpochKeyHex = $stage->epoch->keyHex;

        return $stage->epoch->epochId;
    }

    /**
     * D-10: finalize the epoch-1 keyring file staged by
     * {@see stageFirstEpoch()} — call ONLY after the surrounding SQL
     * transaction has committed.
     *
     * @throws LogicException when stageFirstEpoch() was never called.
     * @throws RuntimeException if the rename fails.
     */
    public function finalizeStagedEpoch(): void
    {
        if ($this->pendingStage === null) {
            throw new LogicException('EncryptionMigrationSupport::finalizeStagedEpoch — no staged epoch to finalize.');
        }

        $this->keyringService->finalizeStagedEpoch($this->pendingStage);
        $this->pendingStage = null;
    }

    /**
     * D-10: discard the un-finalized `.tmp` keyring file staged by
     * {@see stageFirstEpoch()} after the surrounding SQL transaction rolled
     * back. A no-op when nothing was ever staged. Best-effort — never
     * throws (mirrors `GdkKeyringService::discardStagedEpoch()`).
     */
    public function discardStagedEpoch(): void
    {
        if ($this->pendingStage === null) {
            return;
        }

        $this->keyringService->discardStagedEpoch($this->pendingStage);
        $this->pendingStage = null;
    }

    /**
     * WR-01: whether the GDK keyring actually holds a usable key for the
     * epoch recorded in `sync_encryption_state.current_epoch`. A `current_epoch`
     * value being set does NOT prove the keyring file was finalized (a
     * CR-01-class crash in the commit→finalize window can strand the key as a
     * lingering `.tmp`). `EncryptionMigrationService::migrate()` uses this to
     * distinguish "genuinely enabled" from "recorded-enabled but stranded"
     * before it reports success.
     *
     * Returns false (rather than throwing) for the stranded case — "no current
     * epoch recorded" or "keyring has no key for it". Still propagates
     * `LogicException` when the app-lock KEK is unavailable, since the caller
     * only invokes this on the unlocked path.
     *
     * @throws LogicException when the app-lock KEK is unavailable.
     */
    public function hasUsableCurrentEpoch(int $userId, Session $session): bool
    {
        try {
            $this->keyringService->currentEpoch($userId, $session);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Prime this instance's cached epoch from the CURRENT epoch already
     * recorded for $userId (used by a resumed/retried pass where
     * `stageFirstEpoch()`/`finalizeStagedEpoch()` already ran in an
     * earlier attempt).
     *
     * @throws LogicException when the app-lock KEK is unavailable.
     * @throws RuntimeException when no current epoch is recorded.
     */
    public function primeCurrentEpoch(int $userId, Session $session): int
    {
        $epoch = $this->keyringService->currentEpoch($userId, $session);
        $this->cachedEpochId = $epoch->epochId;
        $this->cachedEpochKeyHex = $epoch->keyHex;

        return $epoch->epochId;
    }

    /**
     * Encrypt a single `op_log_entries.value` under the primed epoch,
     * tagged with the exact op-log entry AD `OpLogWriter` itself binds.
     *
     * @return array{value: string, epochId: int}
     */
    public function encryptOpLogValue(string $table, int|string $pk, string $field, string $value): array
    {
        [$epochId, $rawKey] = $this->requirePrimedEpoch();

        try {
            $ciphertext = $this->fieldCrypto->encrypt($value, $rawKey, "{$table}:{$pk}:{$field}:{$epochId}");
        } finally {
            sodium_memzero($rawKey);
        }

        return ['value' => $ciphertext, 'epochId' => $epochId];
    }

    /**
     * Encrypt a single projection-column value under the primed epoch,
     * using the canonical pk-less projection AD
     * (`SensitiveColumnCodec::associatedData`) — byte-for-byte the same AD
     * shape the Plan 03/04 write hooks and read sites already use.
     */
    public function encryptProjectionValue(string $table, string $field, string $value): string
    {
        [$epochId, $rawKey] = $this->requirePrimedEpoch();

        try {
            return $this->fieldCrypto->encrypt($value, $rawKey, SensitiveColumnCodec::associatedData($table, $field, $epochId));
        } finally {
            sodium_memzero($rawKey);
        }
    }

    /**
     * True when $value ALREADY AEAD-verifies as ciphertext under the primed
     * epoch + the exact projection AD — the row-level idempotency check a
     * resumed/retried migration pass uses to never double-encrypt a value.
     */
    public function alreadyEncryptedProjectionValue(string $table, string $field, string $value): bool
    {
        [$epochId, $rawKey] = $this->requirePrimedEpoch();

        try {
            return $this->fieldCrypto->decrypt($value, $rawKey, SensitiveColumnCodec::associatedData($table, $field, $epochId)) !== false;
        } finally {
            sodium_memzero($rawKey);
        }
    }

    /**
     * @return array{0: int, 1: string} [epochId, rawKeyBytes]
     */
    private function requirePrimedEpoch(): array
    {
        if ($this->cachedEpochId === null || $this->cachedEpochKeyHex === null) {
            throw new LogicException('EncryptionMigrationSupport: call stageFirstEpoch()/primeCurrentEpoch() before encrypting.');
        }

        return [$this->cachedEpochId, sodium_hex2bin($this->cachedEpochKeyHex)];
    }
}
