<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use InvalidArgumentException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class WordCodeEncoder
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // RFC 4648 base-32 (alphabet A-Z, 2-7) — omits the ambiguous 0/O/1/I
    // glyphs so the code reads and types cleanly across two screens.
    public function encode(string $tokenHex): string
    {
        $bytes = $this->hexToBytes($tokenHex);
        $base32 = $this->base32Encode($bytes);

        $chunks = str_split($base32, 4);

        return implode('-', $chunks);
    }

    // Dashes and surrounding whitespace are stripped and the input is
    // upper-cased so a user-typed code (lowercase, spaced) still round-trips.
    public function decode(string $wordCode): string
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', trim($wordCode)));
        $bytes = $this->base32Decode($normalized);

        // The token is exactly 16 bytes (128-bit). Reject an over-/under-long
        // paste with a clear "invalid code" error rather than letting a
        // wrong-length hex silently miss the DB lookup.
        if (strlen($bytes) !== 16) {
            throw new InvalidArgumentException('WordCodeEncoder: decoded token is not 16 bytes.');
        }

        return bin2hex($bytes);
    }

    private function hexToBytes(string $tokenHex): string
    {
        $bytes = @hex2bin($tokenHex);
        if ($bytes === false) {
            throw new InvalidArgumentException('WordCodeEncoder: token is not valid hex.');
        }

        return $bytes;
    }

    private function base32Encode(string $bytes): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $index = ($buffer >> $bitsLeft) & 0x1F;
                $output .= self::ALPHABET[$index];
            }
        }

        if ($bitsLeft > 0) {
            $index = ($buffer << (5 - $bitsLeft)) & 0x1F;
            $output .= self::ALPHABET[$index];
        }

        return $output;
    }

    private function base32Decode(string $code): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $index = strpos(self::ALPHABET, $code[$i]);
            if ($index === false) {
                throw new InvalidArgumentException('WordCodeEncoder: invalid character in word-code.');
            }

            $buffer = ($buffer << 5) | $index;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
