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
