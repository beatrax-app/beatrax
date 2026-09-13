<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// How far apart two words are, counted in the characters a reader typed rather
// than in the bytes levenshtein() counts. Every letter outside ASCII costs two
// edits there or three, and both callers divide the distance by a CHARACTER
// length, so the mismatch is what their thresholds were read against.
final class EditDistance
{
    // Single-byte pairs still take the C implementation, which is every
    // comparison in an ASCII-only ledger.
    public static function between(string $a, string $b): int
    {
        if (strlen($a) === mb_strlen($a) && strlen($b) === mb_strlen($b)) {
            return levenshtein($a, $b);
        }

        return self::overCodePoints(mb_str_split($a), mb_str_split($b));
    }

    // 1.0 for two identical words, 0.0 once they share nothing. Two empty
    // strings are identical, which is a caller's problem to refuse rather than
    // this one's to answer.
    public static function similarity(string $a, string $b): float
    {
        $width = max(mb_strlen($a), mb_strlen($b));

        return $width === 0 ? 1.0 : max(0.0, 1.0 - self::between($a, $b) / $width);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function overCodePoints(array $a, array $b): int
    {
        $width = count($b);
        $previous = range(0, $width);

        foreach ($a as $i => $aChar) {
            $current = [$i + 1];
            foreach ($b as $j => $bChar) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($aChar === $bChar ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[$width];
    }
}
