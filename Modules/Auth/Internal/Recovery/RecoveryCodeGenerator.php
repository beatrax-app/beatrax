<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

final class RecoveryCodeGenerator
{
    // Phone-readable alphabet -- 31 characters, excludes I, L, O, 0, 1
    // (visually ambiguous when read or typed aloud).
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const int GROUPS = 5;

    private const int GROUP_LENGTH = 4;

    public function generate(): string
    {
        $maxIndex = strlen(self::ALPHABET) - 1;

        $groups = [];
        for ($group = 0; $group < self::GROUPS; $group++) {
            $chars = '';
            for ($char = 0; $char < self::GROUP_LENGTH; $char++) {
                $chars .= self::ALPHABET[random_int(0, $maxIndex)];
            }
            $groups[] = $chars;
        }

        return implode('-', $groups);
    }
}
