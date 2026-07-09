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
 * Rotation-safe projection-column at-rest codec (D-02b) — Sync Public so
 * every consumer across the module boundary (the op-log replayer's
 * projection write-back in Plan 03, the direct-write/import path + read
 * sites in Plan 04, the D-09 migration in Plan 06, and `SearchIndexWriter`
 * in Modules\Search) shares ONE authoritative encrypt/decrypt scheme for
 * every sensitive PROJECTION column, whether it was written by import OR
 * by an op-log edit.
 *
 * `associatedData(table, field, epochId)` is the canonical PROJECTION AD
 * `"{table}:{field}:{epochId}"` — deliberately pk-less (import's
 * `insertOrIgnore` has no primary key at write time) and DISTINCT from the
 * op-log ENTRY AD `"{table}:{pk}:{field}:{epochId}"` Plan 03 binds into
 * `op_log_entries.value`. The epoch id is bound into the AEAD tag so a
 * relabeled epoch fails authentication, same defense-in-depth posture as
 * the op-log path.
 *
 * `encryptValue()`/`encryptAttrs()` encrypt under the CURRENT epoch only.
 * `decryptValue()`/`decryptRow()` are what make the codec rotation-safe:
 * they TRY EVERY epoch in the loaded keyring (cheap — the keyring is
 * append-only and grows by exactly one epoch per device removal) until one
 * AEAD-verifies, so a value encrypted under epoch N still decrypts after a
 * rotation to N+1. If NONE verify (tampering, corruption, or a genuinely
 * plaintext legacy value pre-dating encryption), the RAW stored value is
 * returned with `decrypted: false` — this codec NEVER throws on a decrypt
 * failure (T-05-03 "false not garbage" posture) and never fabricates
 * ciphertext-looking garbage as if it were real content.
 *
 * When encryption has not been enabled for the user (no `current_epoch`
 * recorded, or the app-lock is not currently unlocked), both directions
 * are a plain pass-through: encrypt returns the value unchanged, decrypt
 * returns the value unchanged with `decrypted: false`.
 */
final class SensitiveColumnCodec
{
    public function __construct(
        private readonly OpLogFieldCrypto $crypto,
        private readonly GdkKeyringService $keyringService,
        private readonly SensitiveFieldRegistry $registry,
    ) {}

    /**
     * The canonical projection-column associated-data string. PUBLIC and
     * STATIC so Plan 04/06/Search call sites can reproduce the exact same
     * AD independently when they need to (e.g. a raw SQL migration pass
     * that does not want to instantiate the full codec).
     */
    public static function associatedData(string $table, string $field, int $epochId): string
    {
        return "{$table}:{$field}:{$epochId}";
    }

    /**
     * Encrypt a single value under the CURRENT epoch. Pass-through
     * (returns $value unchanged) when encryption is not currently usable
     * (not enabled for this user, or the app-lock is locked).
     */
    public function encryptValue(string $table, string $field, string $value, int $userId, Session $session): string
    {
        $current = $this->tryCurrentEpoch($userId, $session);
        if ($current === null) {
            return $value;
        }

        return $this->encryptWithEpoch($table, $field, $value, $current);
    }

    /**
     * Encrypt every SensitiveFieldRegistry-listed, string-valued attribute
     * present in $attrs (in place) under the CURRENT epoch. Non-sensitive
     * or non-string entries are left untouched. Pass-through (returns
     * $attrs unchanged) when encryption is not currently usable.
     *
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

    /**
     * Decrypt a single stored value, trying EVERY epoch in the keyring
     * (rotation-safe). Returns the raw stored value with `decrypted: false`
     * when no epoch verifies (tampering, corruption, or a legacy plaintext
     * value) — NEVER throws.
     *
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

    /**
     * Decrypt every SensitiveFieldRegistry-listed, string-valued column
     * present in $row (in place), trying every epoch per column. Columns
     * that fail to verify under every epoch keep their raw stored value.
     * NEVER throws.
     *
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

        // No epoch verified — false not garbage: return the raw stored
        // value untouched, flagged, never a thrown exception.
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
