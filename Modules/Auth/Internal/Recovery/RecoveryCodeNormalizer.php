<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

final class RecoveryCodeNormalizer
{
    // Same ambiguous-free alphabet the generator draws from -- excludes
    // I, L, O, 0, and 1.
    private const ALLOWED = 'A-HJKMNP-Z2-9';

    public function normalize(string $input): string
    {
        $upper = strtoupper($input);

        return (string) preg_replace('/[^'.self::ALLOWED.']/', '', $upper);
    }
}
