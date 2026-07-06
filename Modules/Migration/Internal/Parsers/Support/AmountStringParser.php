<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

/**
 * Dutch/plain-decimal amount-string parser for YNAB4/nYNAB CSV cells
 * (`Outflow`/`Inflow`/`Budgeted`). Copied VERBATIM from
 * `Modules\Budgets\Public\Services\EnvelopeWriter::parseAmount()` (identical
 * copies already exist in `BudgetWriter`/`GoalWriter`) per 13.5-PATTERNS.md's
 * "Don't Hand-Roll" table and the plan's own `must_haves` truth — this is
 * NOT re-derived, so any future bugfix to the shared parsing rule should be
 * ported here too.
 *
 * Handles the rightmost `.`/`,` disambiguation (Dutch grouped vs plain
 * decimal), a 12-digit whole-part cap, and returns `null` for a blank,
 * malformed, or non-positive value — matching the source method's contract
 * exactly (`0.00`/empty cells common in YNAB's always-two-column
 * Outflow/Inflow shape must resolve to "no amount", not zero).
 */
final class AmountStringParser
{
    public function parse(string $value): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($normalised === '') {
            return null;
        }

        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $normalised = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $normalised)
                : str_replace(',', '', $normalised);
        } elseif ($lastComma !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $normalised, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $minor > 0 ? $minor : null;
    }
}
