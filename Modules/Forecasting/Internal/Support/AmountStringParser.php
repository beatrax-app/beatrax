<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use InvalidArgumentException;

/**
 * Locale-aware money input parser shared by every Forecasting Livewire
 * surface that accepts a free-text amount field.
 *
 * The heuristic is "last-separator wins":
 *
 *   - "12.50"     → 1250  (US-style decimal, dot is the only separator)
 *   - "12,50"     → 1250  (NL-style decimal, comma is the only separator)
 *   - "1,234.56"  → 123456 (US thousands + decimal; dot is the last → decimal)
 *   - "1.234,56"  → 123456 (NL thousands + decimal; comma is the last → decimal)
 *   - "1234"      → 123400
 *   - " 1 234,56" → 123456 (spaces stripped)
 *   - "-12,50"    → -1250 (sign optional unless `$allowNegative` is false)
 *
 * Returns the value in minor units (cents) as a `int`. Throws
 * `InvalidArgumentException` on empty / non-numeric input, or on a
 * negative value when `$allowNegative` is false (e.g. a buffer amount).
 *
 * Centralised in this helper so the four Livewire components that take
 * a money input (AccountBufferEditor, OpeningBalanceEditor,
 * ScenarioEditorSidebar, ModelWhatIfDropdown) stop diverging — the
 * earlier inline implementation in two of them silently 100×-multiplied
 * any dot-decimal input because the dot was being stripped as a
 * thousands separator unconditionally.
 */
final class AmountStringParser
{
    /**
     * Parse a user-typed amount into minor units. Throws on invalid
     * input. `$allowNegative=false` rejects negative numbers (used by
     * the buffer editor, where a negative buffer is meaningless).
     *
     * `$requireNonZero=true` rejects zero (used by the model-what-if
     * dropdown, where a "new amount" of zero is treated as a cancel
     * mutation and the dropdown enforces a positive value).
     */
    public static function toMinor(string $input, bool $allowNegative = true, bool $requireNonZero = false): int
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Amount is required.');
        }

        $negative = false;
        if (str_starts_with($trimmed, '-')) {
            $negative = true;
            $trimmed = ltrim(substr($trimmed, 1));
        } elseif (str_starts_with($trimmed, '+')) {
            $trimmed = ltrim(substr($trimmed, 1));
        }

        // Strip whitespace (forgiving of "1 234,56" style inputs).
        $normalised = str_replace([' '], '', $trimmed);
        $commaPos = strrpos($normalised, ',');
        $dotPos = strrpos($normalised, '.');
        if ($commaPos !== false && $dotPos !== false) {
            // Both present — the LAST separator is the decimal mark; the
            // earlier-occurring one (or copies of it) is a thousands
            // separator and is stripped.
            if ($commaPos > $dotPos) {
                $normalised = str_replace('.', '', $normalised);
                $normalised = str_replace(',', '.', $normalised);
            } else {
                $normalised = str_replace(',', '', $normalised);
            }
        } elseif ($commaPos !== false) {
            // Only commas present — treat as the decimal mark.
            $normalised = str_replace(',', '.', $normalised);
        }
        // Else: only dots (or none). Leave dots in place so "12.50"
        // parses as 12.50 (not 1250).

        if (! is_numeric($normalised)) {
            throw new InvalidArgumentException('Amount must be a number.');
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
}
