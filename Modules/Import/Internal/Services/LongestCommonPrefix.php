<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use InvalidArgumentException;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class LongestCommonPrefix
{
    private const MIN_PREFIX_LENGTH = 4;

    /**
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

        $prefix = trim(self::commonPrefixOf($patterns));

        return mb_strlen($prefix) < self::MIN_PREFIX_LENGTH ? '' : $prefix;
    }

    // An empty member collapses the shared length to zero, so the loop
    // breaks out rather than needing a return per bail-out.
    /**
     * @param  list<string>  $patterns
     */
    private static function commonPrefixOf(array $patterns): string
    {
        $first = mb_strtolower($patterns[0]);
        $minLen = mb_strlen($first);

        foreach ($patterns as $pattern) {
            $minLen = self::sharedLength($first, mb_strtolower($pattern), $minLen);
            if ($minLen === 0) {
                break;
            }
        }

        return mb_substr($first, 0, $minLen);
    }

    private static function sharedLength(string $first, string $other, int $bound): int
    {
        $limit = min($bound, mb_strlen($other));
        $i = 0;
        while ($i < $limit && mb_substr($first, $i, 1) === mb_substr($other, $i, 1)) {
            $i++;
        }

        return $i;
    }
}
