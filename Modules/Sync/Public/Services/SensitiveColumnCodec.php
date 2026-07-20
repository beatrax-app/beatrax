<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use LogicException;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyring;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use RuntimeException;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SensitiveColumnCodec
{
    public function __construct(
        private readonly OpLogFieldCrypto $crypto,
        private readonly GdkKeyringService $keyringService,
        private readonly SensitiveFieldRegistry $registry,
    ) {}

    // PUBLIC and STATIC so other call sites (e.g. a raw SQL migration pass)
    // can reproduce the exact same AD independently without instantiating the full codec.
    public static function associatedData(string $table, string $field, int $epochId): string
    {
        return "{$table}:{$field}:{$epochId}";
    }

    // Pass-through (returns $value unchanged) when encryption is not
    // currently usable (not enabled for this user, or the app-lock is locked).
    public function encryptValue(string $table, string $field, string $value, int $userId, Session $session): string
    {
        $current = $this->tryCurrentEpoch($userId, $session);
        if ($current === null) {
            return $value;
        }

        return $this->encryptWithEpoch($table, $field, $value, $current);
    }

    // Non-sensitive or non-string entries are left untouched. Pass-through
    // (returns $attrs unchanged) when encryption is not currently usable.
    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    public function encryptAttrs(string $table, array $attrs, int $userId, Session $session): array
    {
        $current = $this->tryCurrentEpoch($userId, $session);
        if ($current === null) {
            return $attrs;
        }

        foreach ($attrs as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (! $this->registry->isSensitive($table, $field)) {
                continue;
            }
            $attrs[$field] = $this->encryptWithEpoch($table, $field, $value, $current);
        }

        return $attrs;
    }

    // Tries EVERY epoch in the keyring (rotation-safe). Returns the raw
    // stored value with `decrypted: false` when no epoch verifies
    // (tampering, corruption, or a legacy plaintext value) — NEVER throws.
    /**
     * @return array{value: string, decrypted: bool}
     */
    public function decryptValue(string $table, string $field, string $value, int $userId, Session $session): array
    {
        $keyring = $this->tryLoadKeyring($userId, $session);
        if ($keyring === null) {
            return ['value' => $value, 'decrypted' => false];
        }

        return $this->decryptWithKeyring($table, $field, $value, $keyring);
    }

    // Columns that fail to verify under every epoch keep their raw stored
    // value. NEVER throws.
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function decryptRow(string $table, array $row, int $userId, Session $session): array
    {
        $keyring = $this->tryLoadKeyring($userId, $session);
        if ($keyring === null) {
            return $row;
        }

        foreach ($row as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (! $this->registry->isSensitive($table, $field)) {
                continue;
            }
            $row[$field] = $this->decryptWithKeyring($table, $field, $value, $keyring)['value'];
        }

        return $row;
    }

    private function encryptWithEpoch(string $table, string $field, string $value, GdkEpoch $epoch): string
    {
        $rawKey = sodium_hex2bin($epoch->keyHex);
        try {
            return $this->crypto->encrypt(
                $value,
                $rawKey,
                self::associatedData($table, $field, $epoch->epochId),
            );
        } finally {
            sodium_memzero($rawKey);
        }
    }

    /**
     * @return array{value: string, decrypted: bool}
     */
    private function decryptWithKeyring(string $table, string $field, string $value, GdkKeyring $keyring): array
    {
        foreach ($keyring->epochs() as $epoch) {
            try {
                $rawKey = sodium_hex2bin($epoch->keyHex);
            } catch (SodiumException) {
                continue;
            }

            try {
                $plain = $this->crypto->decrypt(
                    $value,
                    $rawKey,
                    self::associatedData($table, $field, $epoch->epochId),
                );
            } finally {
                sodium_memzero($rawKey);
            }

            if ($plain !== false) {
                return ['value' => $plain, 'decrypted' => true];
            }
        }

        // No epoch verified: return the raw stored value untouched, flagged
        // — never a thrown exception.
        return ['value' => $value, 'decrypted' => false];
    }

    private function tryCurrentEpoch(int $userId, Session $session): ?GdkEpoch
    {
        try {
            return $this->keyringService->currentEpoch($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    private function tryLoadKeyring(int $userId, Session $session): ?GdkKeyring
    {
        try {
            $keyring = $this->keyringService->loadKeyring($userId, $session);
        } catch (LogicException) {
            return null;
        }

        return $keyring->epochs() === [] ? null : $keyring;
    }
}
