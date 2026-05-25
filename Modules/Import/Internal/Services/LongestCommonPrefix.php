<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use InvalidArgumentException;

/**
 * Pure-function longest-common-prefix computer for merchant_aliases
 * bulk-merge. Given a list of two or more generalized merchant patterns,
 * returns the longest shared character prefix (mb-safe, lower-cased,
 * trimmed) — the candidate consolidated pattern the merge dialog
 * prefills before the user confirms.
 *
 * Degenerate-input rules (driven by the "no over-matching after merge"
 * safety constraint):
 *
 *   - count($patterns) < 2  → throws InvalidArgumentException. A merge
 *     of one row is not a merge; callers must guard their UI before
 *     invoking the service.
 *   - any input empty       → returns the empty string. An empty pattern
 *     anywhere in the input set means there is no meaningful shared
 *     prefix, so the merge dialog must not auto-fill anything.
 *   - LCP shorter than 4    → returns the empty string. A 1-3 character
 *     prefix would match thousands of unrelated rows in a typical
 *     statement history; refusing to surface it forces the user to
 *     hand-write a longer pattern in the merge dialog before confirming.
 *
 * The service is stateless and has no collaborators, so it is bound as
 * a singleton in `ImportServiceProvider`. All comparisons run through
 * `mb_substr` + `mb_strlen` so Unicode descriptions (emoji prefixes,
 * accented merchant names) survive intact.
 */
final class LongestCommonPrefix
{
    /**
     * Minimum LCP length, in mb characters. A 1-3 character prefix
     * would over-match across unrelated merchants — refusing to
     * surface it as a merge target prevents the bulk-merge dialog
     * from setting up a destructive over-match.
     */
    private const MIN_PREFIX_LENGTH = 4;

    /**
     * Returns the lower-cased, trimmed longest common prefix of
     * `$patterns`, or the empty string when no safe prefix exists.
     *
     * Inputs may be mixed-case or pre-lowercased; the result is
     * always lowercase to match `PatternGeneralizer::generalize()`'s
     * output convention. Most callers feed already-generalized
     * patterns (so the case-fold is a no-op) but the helper performs
     * its own casefold so an upstream variant that bypasses the
     * generalizer still gets a deterministic, case-insensitive prefix.
     *
     * @param  list<string>  $patterns
     *
     * @throws InvalidArgumentException when `$patterns` carries fewer
     *                                  than two entries
     */
    public function compute(array $patterns): string
    {
        if (count($patterns) < 2) {
            throw new InvalidArgumentException(
                'LongestCommonPrefix requires at least two patterns.',
            );
        }

        $first = mb_strtolower($patterns[0]);
        if ($first === '') {
            return '';
        }

        $minLen = mb_strlen($first);
        foreach ($patterns as $pattern) {
            $other = mb_strtolower($pattern);
            if ($other === '') {
                return '';
            }
            $i = 0;
            $bound = min($minLen, mb_strlen($other));
            while ($i < $bound && mb_substr($first, $i, 1) === mb_substr($other, $i, 1)) {
                $i++;
            }
            $minLen = $i;
            if ($minLen === 0) {
                return '';
            }
        }

        $prefix = trim(mb_substr($first, 0, $minLen));
        if (mb_strlen($prefix) < self::MIN_PREFIX_LENGTH) {
            return '';
        }

        return $prefix;
    }
}
