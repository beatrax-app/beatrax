<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

/**
 * Generates a single account-recovery code.
 *
 * Each code is twenty characters drawn from a 31-character phone-readable
 * alphabet that excludes the visually ambiguous glyphs I, L, O, 0 and 1.
 * The characters are laid out as five hyphen-separated groups of four
 * (for example `A2BJ-XK9M-PQ7N-RX4F-V8HD`), which is easy to read aloud
 * and to type. Every character is drawn with `random_int`, the
 * cryptographically secure PRNG, giving roughly 99 bits of entropy per
 * code.
 */
final class RecoveryCodeGenerator
{
    /** Phone-readable alphabet — 31 characters, excludes I, L, O, 0, 1. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const GROUPS = 5;

    private const GROUP_LENGTH = 4;

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
