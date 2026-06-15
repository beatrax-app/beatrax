<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use RuntimeException;

/**
 * Derive a word-based safety-number from two public keys (D-07/D-08, T-12-07).
 *
 * Both peers compute the SAME 6-word number because the two keys are
 * binary-sorted before hashing — the derivation is order-independent and
 * deterministic. A MITM that substitutes its own key cannot force a matching
 * safety-number, which is what the mandatory both-screen confirmation (D-07)
 * relies on.
 *
 * 6 words × 11 bits ≈ 66 bits of safety-number space — ample for a
 * human-verification fingerprint (collision is ~1/2^66).
 */
final class SafetyNumberDeriver
{
    /**
     * @param  list<string>  $wordList  2048-word BIP39 English list (Bip39WordList::WORDS).
     */
    public function __construct(private readonly array $wordList) {}

    /**
     * Derive the 6-word safety-number from two raw 32-byte public keys.
     *
     * @param  string  $pubKeyA  Raw 32-byte Ed25519 public key.
     * @param  string  $pubKeyB  Raw 32-byte Ed25519 public key.
     * @return list<string> Exactly 6 BIP39 words, order-independent.
     */
    public function derive(string $pubKeyA, string $pubKeyB): array
    {
        // A safety-number derived from a short/over-long key is meaningless
        // (WR-01): assert the raw 32-byte Ed25519 length up front rather than
        // silently hashing junk.
        if (strlen($pubKeyA) !== 32 || strlen($pubKeyB) !== 32) {
            throw new InvalidPublicKeyException('SafetyNumberDeriver: public keys must be exactly 32 raw bytes.');
        }

        $keys = [$pubKeyA, $pubKeyB];
        sort($keys); // Binary sort → identical order regardless of argument order.
        $hash = hash('sha256', implode('', $keys), true); // 32 raw bytes.

        $words = [];
        for ($i = 0; $i < 6; $i++) {
            $chunk = substr($hash, $i * 2, 2);
            $unpacked = unpack('n', $chunk);
            // PHPStan L10 guard — unpack() is typed array<int|string,int>|false.
            if ($unpacked === false || ! isset($unpacked[1]) || ! is_int($unpacked[1])) {
                throw new RuntimeException('SafetyNumberDeriver: could not unpack word index.');
            }
            $words[] = $this->wordList[$unpacked[1] % 2048];
        }

        return $words;
    }

    /**
     * Convenience wrapper for hex-encoded inputs — the Livewire / pairing
     * layer holds public keys as hex. Decodes to raw bytes, then derives.
     *
     * @return list<string> Exactly 6 BIP39 words, order-independent.
     */
    public function deriveWords(string $pubKeyAHex, string $pubKeyBHex): array
    {
        return $this->derive(
            self::hexToRawKey($pubKeyAHex),
            self::hexToRawKey($pubKeyBHex),
        );
    }

    /**
     * Validate a public key is exactly 64 lowercase hex chars and decode it to
     * its raw 32 bytes, throwing a typed domain exception (NOT a raw
     * SodiumException) on malformed input so the Livewire layer can handle it as
     * the generic "invalid code" flash rather than a 500 (WR-01).
     */
    public static function hexToRawKey(string $hex): string
    {
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex) || strtolower($hex) !== $hex) {
            throw new InvalidPublicKeyException('Public key must be exactly 64 lowercase hex characters.');
        }

        try {
            return sodium_hex2bin($hex);
        } catch (\SodiumException $e) {
            throw new InvalidPublicKeyException('Public key is not valid hex.', 0, $e);
        }
    }
}
