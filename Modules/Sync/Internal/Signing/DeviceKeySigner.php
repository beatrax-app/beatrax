<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Signing;

use SodiumException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class DeviceKeySigner
{
    /**
     * @param  string  $payload  Canonical signing payload (from OpLogEntry::signingPayload()).
     * @param  string  $secretKeyBin  Raw 64-byte Ed25519 secret key (from sodium_crypto_sign_secretkey()).
     */
    public function sign(string $payload, string $secretKeyBin): string
    {
        if ($secretKeyBin === '') {
            throw new \InvalidArgumentException('DeviceKeySigner::sign — secretKeyBin must not be empty.');
        }

        return sodium_bin2hex(
            sodium_crypto_sign_detached($payload, $secretKeyBin),
        );
    }

    // Returns false (never throws) on any malformed input — invalid hex,
    // wrong key length, or a corrupted signature.
    /**
     * @param  string  $payload  The payload that was signed.
     * @param  string  $sigHex  Hex-encoded 64-byte Ed25519 signature.
     * @param  string  $publicKeyBin  Raw 32-byte Ed25519 public key.
     */
    public function verify(string $payload, string $sigHex, string $publicKeyBin): bool
    {
        if ($sigHex === '' || $publicKeyBin === '') {
            return false;
        }

        try {
            $sigBin = sodium_hex2bin($sigHex);

            // A blank decode short-circuits to false rather than reaching the
            // AEAD verify — an empty signature can never be valid.
            return $sigBin !== ''
                && sodium_crypto_sign_verify_detached($sigBin, $payload, $publicKeyBin);
        } catch (SodiumException) {
            return false;
        }
    }
}
