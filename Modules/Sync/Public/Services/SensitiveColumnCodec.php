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
            // No keyring is the ordinary state for a user without encryption
            // (values are plaintext) but ALSO for a phone holding synced rows
            // it has no key for. Shape separates the two.
            return self::safeUndecrypted($value);
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

        foreach ($row as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (! $this->registry->isSensitive($table, $field)) {
                continue;
            }
            // Same treatment with or without a keyring: without one, a
            // sensitive column holding ciphertext is unreadable here too, and
            // returning the row untouched put base64 straight on the screen.
            $row[$field] = $keyring === null
                ? self::safeUndecrypted($value)['value']
                : $this->decryptWithKeyring($table, $field, $value, $keyring)['value'];
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

        // A keyring exists and nothing opened this value: either a pre-
        // encryption row (plaintext) or ciphertext whose epoch this device
        // lacks. Passing it through is right for the first, wrong for the
        // second — a phone that lost its keyring rendered base64 as names.

        // Shape decides: ciphertext is base64(nonce||ct), so at least 40 bytes
        // and no spaces. "ALBERT HEIJN 1234" fails on the space alone.
        return self::safeUndecrypted($value);
    }

    // One place decides what an unreadable value looks like to a caller, so
    // the no-keyring and no-matching-epoch paths can never drift apart.
    /**
     * @return array{value: string, decrypted: bool}
     */
    private static function safeUndecrypted(string $value): array
    {
        return [
            'value' => self::looksLikeCiphertext($value) ? '' : $value,
            'decrypted' => false,
        ];
    }

    // Deliberately conservative: it must never blank a real description, so
    // anything that could plausibly be human text passes through untouched.
    private static function looksLikeCiphertext(string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) !== 1) {
            return false;
        }

        $decoded = base64_decode($value, true);

        return $decoded !== false && strlen($decoded) >= 40;
    }

    private function tryCurrentEpoch(int $userId, Session $session): ?GdkEpoch
    {
        try {
            return $this->keyringService->currentEpoch($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    // RuntimeException too, matching tryCurrentEpoch. A held key that cannot
    // open the keyring raises BackupDecryptionException, which escaped — so
    // one wrong key 500'd every screen reading an encrypted column, with no
    // way back to the lock that would replace it.
    private function tryLoadKeyring(int $userId, Session $session): ?GdkKeyring
    {
        try {
            $keyring = $this->keyringService->loadKeyring($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }

        return $keyring->epochs() === [] ? null : $keyring;
    }
}
