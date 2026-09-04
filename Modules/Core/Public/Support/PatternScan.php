<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Public\Exceptions\PatternScanFailedException;

// `preg_match_all` returns false on a PCRE limit and leaves the match array
// empty, so a scan that never ran and a scan that found nothing are the same
// two values at the call site. An architecture guard that hit the JIT stack
// limit reported a clean tree; this is the one reading that cannot.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-regex-that-never-ran-read-as-no-match
 */
final class PatternScan
{
    public static function matches(string $pattern, string $subject): bool
    {
        return self::tally(preg_match($pattern, $subject), $pattern) === 1;
    }

    public static function count(string $pattern, string $subject): int
    {
        return self::tally(preg_match_all($pattern, $subject), $pattern);
    }

    /**
     * @return array<int|string, string>
     */
    public static function first(string $pattern, string $subject): array
    {
        $matches = [];
        self::tally(preg_match($pattern, $subject, $matches), $pattern);

        return $matches;
    }

    /**
     * @return array<int|string, array{0: string, 1: int}>
     */
    public static function firstWithOffsets(string $pattern, string $subject): array
    {
        $matches = [];
        self::tally(preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE), $pattern);

        return $matches;
    }

    /**
     * @return array<int|string, list<string>>
     */
    public static function all(string $pattern, string $subject): array
    {
        $matches = [];
        self::tally(preg_match_all($pattern, $subject, $matches), $pattern);

        return $matches;
    }

    /**
     * @return array<int|string, list<array{0: string, 1: int}>>
     */
    public static function allWithOffsets(string $pattern, string $subject): array
    {
        $matches = [];
        self::tally(preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE), $pattern);

        return $matches;
    }

    /**
     * @return list<array<int|string, string>>
     */
    public static function sets(string $pattern, string $subject): array
    {
        $matches = [];
        self::tally(preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER), $pattern);

        return $matches;
    }

    /**
     * @return list<array<int|string, array{0: string, 1: int}>>
     */
    public static function setsWithOffsets(string $pattern, string $subject): array
    {
        $matches = [];
        self::tally(preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE), $pattern);

        return $matches;
    }

    // The quieter half of the same failure. A caller writing
    // `(string) preg_replace(…)` turns a give-up into an EMPTY subject, and
    // whatever scans that subject next reports nothing found — a defect with
    // no count to look wrong.
    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        return self::rewritten(preg_replace($pattern, $replacement, $subject), $pattern);
    }

    /**
     * @param  callable(array<int|string, string>): string  $replacement
     */
    public static function replaceCallback(string $pattern, callable $replacement, string $subject): string
    {
        return self::rewritten(preg_replace_callback($pattern, $replacement, $subject), $pattern);
    }

    // The error code is read as well as the return, because the two answer
    // different questions: false says PCRE gave up, and a non-zero code says
    // which limit it gave up on — the difference between a pattern to rewrite
    // and a subject too large for the ini bound the run was given.
    private static function tally(int|false $result, string $pattern): int
    {
        $code = preg_last_error();

        if ($result === false || $code !== PREG_NO_ERROR) {
            throw new PatternScanFailedException($pattern, preg_last_error_msg());
        }

        return $result;
    }

    private static function rewritten(?string $result, string $pattern): string
    {
        if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new PatternScanFailedException($pattern, preg_last_error_msg());
        }

        return $result;
    }
}
