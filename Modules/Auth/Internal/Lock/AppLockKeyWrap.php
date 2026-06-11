<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

/**
 * Wraps and unwraps a data key using XSalsa20-Poly1305 secretbox (T-05-02).
 *
 * The wrapped blob is base64(nonce || ciphertext) — a single column value
 * that is self-contained: it can be stored in `pin_wrapped_key` or
 * `password_wrapped_key` without any separate nonce column.
 *
 * The "false not garbage" posture from BackupEncryptor is enforced here:
 * unwrap() returns false on any failure (wrong key, tampered ciphertext,
 * truncated blob, bad base64) rather than throwing or returning corrupted
 * bytes. Callers MUST treat a false return as "do not use this key".
 */
final class AppLockKeyWrap
{
    /**
     * Wrap a data key under the given wrap key.
     *
     * Generates a fresh random nonce for every call. Returns a
     * base64-encoded string of the form base64(nonce || ciphertext).
     *
     * @param  string  $dataKey  The raw key bytes to protect (typically 32 bytes).
     * @param  string  $wrapKey  A 32-byte secretbox key (e.g. from AppLockKdf).
     */
    public function wrap(string $dataKey, string $wrapKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($dataKey, $nonce, $wrapKey);

        return base64_encode($nonce.$ciphertext);
    }

    /**
     * Unwrap a stored blob back to the original data key.
     *
     * Returns the raw data-key bytes on success, or false if:
     *   - $stored is not valid base64,
     *   - the decoded blob is too short (≤ NONCEBYTES) to contain a ciphertext,
     *   - authentication fails (wrong wrap key, tampered ciphertext, truncation).
     *
     * Never throws; always returns string|false so callers can use a
     * strict === false check.
     */
    public function unwrap(string $stored, string $wrapKey): string|false
    {
        $decoded = base64_decode($stored, strict: true);
        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // sodium_crypto_secretbox_open returns false on any authentication failure.
        return sodium_crypto_secretbox_open($ciphertext, $nonce, $wrapKey);
    }
}
