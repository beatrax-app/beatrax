<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use InvalidArgumentException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final class AmountStringParser
{
    /**
     * @param  bool  $allowNegative  false rejects negative numbers (used by
     *                               the buffer editor, where a negative buffer is meaningless)
     * @param  bool  $requireNonZero  true rejects zero (used by the
     *                                model-what-if dropdown, where a zero "new amount" is treated as
     *                                a cancel mutation and the dropdown enforces a positive value)
     */
    public static function toMinor(string $input, bool $allowNegative = true, bool $requireNonZero = false): int
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Amount is required.');
        }

        $negative = false;
        $trimmed = self::stripSign($trimmed, $negative);

        $normalised = self::normaliseDecimalSeparators(str_replace([' '], '', $trimmed));

        if (! is_numeric($normalised)) {
            throw new InvalidArgumentException('Amount must be a number.');
        }

        // Reject a stripped leading '-' if the caller has banned negatives.
        // (The float value at this point is unsigned because the sign was
        // peeled off before `str_starts_with('-')` above.)
        if (! $allowNegative && $negative) {
            throw new InvalidArgumentException('Amount must be zero or positive.');
        }

        $float = (float) $normalised;
        if (! $allowNegative && $float < 0) {
            throw new InvalidArgumentException('Amount must be zero or positive.');
        }

        $minor = (int) round($float * 100);
        if ($negative) {
            $minor = -$minor;
        }
        if ($requireNonZero && $minor === 0) {
            throw new InvalidArgumentException('Amount must be non-zero.');
        }

        return $minor;
    }

    /**
     * @param  bool  $negative  set to true when a leading '-' is stripped
     */
    private static function stripSign(string $trimmed, bool &$negative): string
    {
        if (str_starts_with($trimmed, '-')) {
            $negative = true;

            return ltrim(substr($trimmed, 1));
        }
        if (str_starts_with($trimmed, '+')) {
            return ltrim(substr($trimmed, 1));
        }

        return $trimmed;
    }

    private static function normaliseDecimalSeparators(string $normalised): string
    {
        $commaPos = strrpos($normalised, ',');
        $dotPos = strrpos($normalised, '.');
        if ($commaPos !== false && $dotPos !== false) {
            // Both present — the LAST separator is the decimal mark; the
            // earlier-occurring one (or copies of it) is a thousands
            // separator and is stripped.
            if ($commaPos > $dotPos) {
                return str_replace(',', '.', str_replace('.', '', $normalised));
            }

            return str_replace(',', '', $normalised);
        }
        if ($commaPos !== false) {
            return str_replace(',', '.', $normalised);
        }

        // Else: only dots (or none). Leave dots in place so "12.50"
        // parses as 12.50 (not 1250).
        return $normalised;
    }
}
