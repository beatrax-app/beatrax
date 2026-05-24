<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Parses a short duration token like `30d`, `12h`, or `2w` into a
 * CarbonImmutable offset by subtracting the requested span from a
 * caller-supplied "now". Used by `beatrax:failed-jobs prune
 * --older-than=<token>` to compute the cutoff timestamp on the
 * `failed_jobs.failed_at` column.
 *
 * Accepted grammar: `/^(\d+)([dhw])$/i` — digits + a single unit letter.
 *  - `d` = days
 *  - `h` = hours
 *  - `w` = weeks
 *
 * The `m` token is intentionally NOT accepted. Across the SI-style
 * grammars that look similar to this one (`m` could mean either
 * "minutes" or "months") the disambiguation cost is higher than the
 * value: callers asking for sub-day cutoffs pass `1h` / `12h`, and
 * callers asking for month-scale cutoffs pass `30d` / `4w`. The
 * narrower grammar means every consumer of this parser knows exactly
 * what they got.
 *
 * Invalid input throws `InvalidArgumentException` carrying the
 * expected regex and the rejected token so the caller can surface the
 * grammar back to the operator.
 *
 * The class is a pure-logic value object (no IO, no facades, no
 * Carbon-facade reads). Tests inject a frozen `CarbonImmutable` for
 * deterministic arithmetic.
 */
final class DurationParser
{
    /**
     * Returns `$now` minus the duration named by `$input`. Throws on
     * any token that does not match the documented grammar.
     *
     * Zero amounts (`0d` / `0h` / `0w`) are rejected: they resolve to
     * `now - 0 = now`, which when paired with the failed-jobs prune
     * predicate `WHERE failed_at < $cutoff` deletes every failed_jobs
     * row regardless of age. A typo of `0d` for `30d` would be
     * catastrophic; the dry-run default mitigates the typo but the
     * grammar itself should refuse the load-bearing footgun. Callers
     * that genuinely want "delete everything" can pass `--all` (TBD)
     * rather than abusing a zero token.
     */
    public function subFromNow(string $input, CarbonImmutable $now): CarbonImmutable
    {
        if (preg_match('/^(\d+)([dhw])$/i', $input, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "Duration must match /^\\d+[dhw]\$/ (e.g. '30d', '7d', '12h', '2w'). Got: '%s'.",
                $input,
            ));
        }

        $amount = (int) $matches[1];
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(
                "Duration amount must be a positive integer (zero would delete every row). Got: '%s'.",
                $input,
            ));
        }
        $unit = strtolower($matches[2]);

        // The regex character class above already guarantees `$unit` is
        // one of `d|h|w`. The default arm is included so PHPStan's
        // match-exhaustiveness analysis stays happy without depending
        // on cross-statement narrowing from the preg_match guard.
        return match ($unit) {
            'd' => $now->subDays($amount),
            'h' => $now->subHours($amount),
            'w' => $now->subWeeks($amount),
            default => throw new InvalidArgumentException(sprintf(
                "Duration must match /^\\d+[dhw]\$/ (e.g. '30d', '7d', '12h', '2w'). Got: '%s'.",
                $input,
            )),
        };
    }
}
