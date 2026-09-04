<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Public\Exceptions\PatternScanFailedException;

// A scan that never ran and a scan that found nothing are the same two values
// at the call site: `preg_match_all` returns false and leaves the match array
// empty, `preg_replace` returns null so a cast blanks the subject, and
// `preg_split` returns false so a coalesce reads as "this input had no parts".
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
    /**
     * @param  string|list<string>  $pattern
     * @param  string|list<string>  $replacement
     */
    public static function replace(string|array $pattern, string|array $replacement, string $subject): string
    {
        $replaced = preg_replace($pattern, $replacement, $subject);

        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            throw self::gaveUp($pattern);
        }

        return $replaced;
    }

    /**
     * @param  callable(array<int|string, string>): string  $replacement
     */
    public static function replaceCallback(string $pattern, callable $replacement, string $subject): string
    {
        $replaced = preg_replace_callback($pattern, $replacement, $subject);

        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            throw self::gaveUp($pattern);
        }

        return $replaced;
    }

    /**
     * @return list<string>
     */
    public static function split(string $pattern, string $subject): array
    {
        $parts = preg_split($pattern, $subject);

        if ($parts === false || preg_last_error() !== PREG_NO_ERROR) {
            throw self::gaveUp($pattern);
        }

        return $parts;
    }

    // The error code is read as well as the return, because the two answer
    // different questions: the null or false says PCRE gave up, and a non-zero
    // code says which limit it gave up on — the difference between a pattern to
    // rewrite and a subject too large for the ini bound the run was given.
    private static function tally(int|false $result, string $pattern): int
    {
        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            throw self::gaveUp($pattern);
        }

        return $result;
    }

    /**
     * @param  string|list<string>  $pattern
     */
    private static function gaveUp(string|array $pattern): PatternScanFailedException
    {
        return new PatternScanFailedException(
            is_array($pattern) ? implode(', ', $pattern) : $pattern,
            preg_last_error_msg(),
        );
    }
}
