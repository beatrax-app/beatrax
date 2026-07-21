<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Wraps and unwraps a data key using XSalsa20-Poly1305 secretbox. The
// wrapped blob is base64(nonce || ciphertext) -- self-contained, so it can
// be stored in pin_wrapped_key or password_wrapped_key without a separate
// nonce column.
final class AppLockKeyWrap
{
    /**
     * @param  string  $dataKey  The raw key bytes to protect (typically 32 bytes).
     * @param  string  $wrapKey  A 32-byte secretbox key (e.g. from AppLockKdf).
     */
    public function wrap(string $dataKey, string $wrapKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($dataKey, $nonce, $wrapKey);

        return base64_encode($nonce.$ciphertext);
    }

    // Returns false rather than throwing or returning corrupted bytes on
    // any failure (wrong key, tampered ciphertext, truncated blob, bad
    // base64) -- callers must treat a false return as "do not use this key".
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

        return sodium_crypto_secretbox_open($ciphertext, $nonce, $wrapKey);
    }
}
