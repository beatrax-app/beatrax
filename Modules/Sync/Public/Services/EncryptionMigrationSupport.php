<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use LogicException;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use RuntimeException;

/**
 * Sync Public surface consumed by
 * `Modules\Core\Public\Services\EncryptionMigrationService` (Phase 14 Plan
 * 06, D-09). The enable-time backup-first migration needs two Internal Sync
 * crypto capabilities no OTHER Public class exposes:
 *
 *   1. First-epoch generation (`GdkKeyringService::generateAndPersist`).
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
 * happens once, in `enableFirstEpoch()`/`primeCurrentEpoch()`).
 */
final class EncryptionMigrationSupport
{
    private ?int $cachedEpochId = null;

    private ?string $cachedEpochKeyHex = null;

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
     * Generate + persist GDK epoch 1 (group-of-one) for $userId and prime
     * this instance's cached epoch for the encrypt*() calls that follow.
     * Returns only the plain integer epoch id — never the raw key bytes.
     *
     * @throws LogicException when the app-lock KEK is unavailable.
     */
    public function enableFirstEpoch(int $userId, Session $session): int
    {
        $epoch = $this->keyringService->generateAndPersist($userId, $session);
        $this->cachedEpochId = $epoch->epochId;
        $this->cachedEpochKeyHex = $epoch->keyHex;

        return $epoch->epochId;
    }

    /**
     * Prime this instance's cached epoch from the CURRENT epoch already
     * recorded for $userId (used by a resumed/retried pass where
     * `enableFirstEpoch()` already ran in an earlier attempt).
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
            throw new LogicException('EncryptionMigrationSupport: call enableFirstEpoch()/primeCurrentEpoch() before encrypting.');
        }

        return [$this->cachedEpochId, sodium_hex2bin($this->cachedEpochKeyHex)];
    }
}
