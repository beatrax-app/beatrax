<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Signing;

use SodiumException;

/**
 * Ed25519 stub signer/verifier for per-device-signed op-log entries.
 *
 * Uses sodium_crypto_sign_detached / sodium_crypto_sign_verify_detached
 * directly — the same ext-sodium API proven in
 * Modules/Core/Public/Services/ElectronUpdateChannel::verifyManifest().
 *
 * This is a spike stub: throwaway keypairs are generated via
 * sodium_crypto_sign_keypair() in tests. Phase 12 owns real device
 * identity and key lifecycle.
 *
 * The class is final (not readonly) to allow future state (e.g., a
 * cached keypair or a key-store reference) without a contract change.
 */
final class DeviceKeySigner
{
    /**
     * Signs $payload with the device's Ed25519 secret key and returns
     * the 64-byte detached signature as a lowercase hex string.
     *
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

    /**
     * Verifies $payload against a hex-encoded $sigHex using the device's
     * Ed25519 public key.
     *
     * Returns false (never throws) on any malformed input — invalid hex,
     * wrong key length, or a corrupted signature. This mirrors the
     * ElectronUpdateChannel try/catch(SodiumException) pattern.
     *
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

            if ($sigBin === '') {
                return false;
            }

            return sodium_crypto_sign_verify_detached(
                $sigBin,
                $payload,
                $publicKeyBin,
            );
        } catch (SodiumException) {
            return false;
        }
    }
}
