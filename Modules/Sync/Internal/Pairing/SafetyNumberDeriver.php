<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use RuntimeException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SafetyNumberDeriver
{
    /**
     * @param  list<string>  $wordList  2048-word BIP39 English list (Bip39WordList::WORDS).
     */
    public function __construct(private readonly array $wordList) {}

    /**
     * @param  string  $pubKeyA  Raw 32-byte Ed25519 public key.
     * @param  string  $pubKeyB  Raw 32-byte Ed25519 public key.
     * @return list<string> Exactly 6 BIP39 words, order-independent.
     */
    public function derive(string $pubKeyA, string $pubKeyB): array
    {
        // A safety-number derived from a short/over-long key is meaningless:
        // assert the raw 32-byte Ed25519 length up front rather than
        // silently hashing junk.
        if (strlen($pubKeyA) !== 32 || strlen($pubKeyB) !== 32) {
            throw new InvalidPublicKeyException('SafetyNumberDeriver: public keys must be exactly 32 raw bytes.');
        }

        // Binary sort so both peers derive an identical key order regardless
        // of which side called derive() with args in which order.
        $keys = [$pubKeyA, $pubKeyB];
        sort($keys);
        $hash = hash('sha256', implode('', $keys), true);

        $words = [];
        for ($i = 0; $i < 6; $i++) {
            $chunk = substr($hash, $i * 2, 2);
            $unpacked = unpack('n', $chunk);
            if ($unpacked === false || ! isset($unpacked[1]) || ! is_int($unpacked[1])) {
                throw new RuntimeException('SafetyNumberDeriver: could not unpack word index.');
            }
            $words[] = $this->wordList[$unpacked[1] % 2048];
        }

        return $words;
    }

    // Convenience wrapper for hex-encoded inputs — the Livewire/pairing
    // layer holds public keys as hex. Decodes to raw bytes, then derives.
    /**
     * @return list<string> Exactly 6 BIP39 words, order-independent.
     */
    public function deriveWords(string $pubKeyAHex, string $pubKeyBHex): array
    {
        return $this->derive(
            self::hexToRawKey($pubKeyAHex),
            self::hexToRawKey($pubKeyBHex),
        );
    }

    // Validates a public key is exactly 64 lowercase hex chars and decodes
    // it to its raw 32 bytes, throwing a typed domain exception (NOT a raw
    // SodiumException) so the Livewire layer can surface the generic
    // "invalid code" flash rather than a 500.
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
