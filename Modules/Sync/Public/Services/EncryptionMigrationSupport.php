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

final class EncryptionMigrationSupport
{
    private ?int $cachedEpochId = null;

    private ?string $cachedEpochKeyHex = null;

    private ?string $cachedBlindIndexKeyHex = null;

    // The pending stage from stageFirstEpoch(), passed back to
    // finalizeStagedEpoch()/discardStagedEpoch() by the caller.
    private ?GdkKeyringStage $pendingStage = null;

    public function __construct(
        private readonly GdkKeyringService $keyringService,
        private readonly OpLogFieldCrypto $fieldCrypto,
        private readonly SensitiveFieldRegistry $registry,
    ) {}

    // Passthrough for SensitiveFieldRegistry::isSensitive() so Core never
    // has to reach the Internal registry directly.
    public function isSensitive(string $table, string $field): bool
    {
        return $this->registry->isSensitive($table, $field);
    }

    // STAGE (do not finalize) GDK epoch 1 and prime this instance's cached
    // epoch. The keyring FILE is not renamed into place yet — the caller
    // MUST call finalizeStagedEpoch() after commit, or discardStagedEpoch()
    // on rollback. Returns only the plain integer epoch id.
    /**
     * @throws LogicException when the app-lock KEK is unavailable.
     */
    public function stageFirstEpoch(int $userId, Session $session): int
    {
        $stage = $this->keyringService->stageFirstEpoch($userId, $session);
        $this->pendingStage = $stage;
        $this->cachedEpochId = $stage->epoch->epochId;
        $this->cachedEpochKeyHex = $stage->epoch->keyHex;
        $this->cachedBlindIndexKeyHex = $stage->blindIndexKeyHex;

        return $stage->epoch->epochId;
    }

    // The blind-index key minted alongside the staged epoch. Available before
    // the keyring file is renamed into place, which is when the counterparty
    // sweep has to run — inside the same transaction as the epoch write.
    /**
     * @throws LogicException when stageFirstEpoch() was never called.
     */
    public function stagedBlindIndexKeyHex(): string
    {
        if ($this->cachedBlindIndexKeyHex === null) {
            throw new LogicException('EncryptionMigrationSupport::stagedBlindIndexKeyHex — call stageFirstEpoch() first.');
        }

        return $this->cachedBlindIndexKeyHex;
    }

    /**
     * @throws LogicException when the app-lock KEK is unavailable.
     */
    public function ensureBlindIndexKey(int $userId, Session $session): string
    {
        return $this->keyringService->ensureBlindIndexKey($userId, $session);
    }

    // Finalize the epoch-1 keyring file staged by stageFirstEpoch() — call
    // ONLY after the surrounding SQL transaction has committed.
    /**
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

    // Discard the un-finalized .tmp keyring file staged by stageFirstEpoch()
    // after a rollback. No-op when nothing was staged. Never throws.
    public function discardStagedEpoch(): void
    {
        if ($this->pendingStage === null) {
            return;
        }

        $this->keyringService->discardStagedEpoch($this->pendingStage);
        $this->pendingStage = null;
    }

    // A recorded current epoch does NOT prove the keyring file was
    // finalized (see architecture doc: stranded-epoch case). Distinguishes
    // "genuinely enabled" from "recorded but stranded" — false, not thrown,
    // for the stranded case.
    /**
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

    // Prime this instance's cached epoch from the CURRENT epoch already
    // recorded (used by a resumed/retried pass after an earlier attempt).
    /**
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

    // Encrypt a single op_log_entries.value under the primed epoch, tagged
    // with the exact op-log entry AD OpLogWriter itself binds.
    /**
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

    // Encrypt a single projection-column value under the primed epoch,
    // using the canonical pk-less projection AD (SensitiveColumnCodec::
    // associatedData) — the same shape existing write/read paths use.
    public function encryptProjectionValue(string $table, string $field, string $value): string
    {
        [$epochId, $rawKey] = $this->requirePrimedEpoch();

        try {
            return $this->fieldCrypto->encrypt($value, $rawKey, SensitiveColumnCodec::associatedData($table, $field, $epochId));
        } finally {
            sodium_memzero($rawKey);
        }
    }

    // True when $value ALREADY AEAD-verifies under the primed epoch + the
    // exact projection AD — the idempotency check a resumed/retried
    // migration pass uses to never double-encrypt a value.
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
