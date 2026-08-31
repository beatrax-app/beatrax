<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

// chain_links.confidence is DECIMAL(4,3) and both resolvers write it as a
// string, so the scale it is rendered at is the column's, not the caller's.
final class ConfidenceScale
{
    private const int DECIMALS = 3;

    public static function format(float $value): string
    {
        return number_format($value, self::DECIMALS, '.', '');
    }
}
