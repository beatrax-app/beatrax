<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Modules\Core\Public\Support\PatternScan;

final class RecoveryCodeNormalizer
{
    // Same ambiguous-free alphabet the generator draws from -- excludes
    // I, L, O, 0, and 1.
    private const string ALLOWED = 'A-HJKMNP-Z2-9';

    public function normalize(string $input): string
    {
        $upper = strtoupper($input);

        return PatternScan::replace('/[^'.self::ALLOWED.']/', '', $upper);
    }
}
