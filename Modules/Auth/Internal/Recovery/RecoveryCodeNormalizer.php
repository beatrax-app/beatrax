<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

/**
 * Normalises a typed account-recovery code into its bare comparison form.
 *
 * A user transcribes a recovery code by hand: case may vary and hyphens
 * or spaces may be added, dropped or misplaced. `normalize()` folds the
 * input to uppercase and keeps only the characters of the phone-readable
 * recovery-code alphabet - the same ambiguous-free set the generator
 * draws from: A-H, J, K, M, N, P-Z and 2-9. The glyphs I, L, O, 0 and 1
 * are excluded because they are easy to confuse when read or typed. The
 * result is the bare twenty-character code with no separators; the
 * authenticator re-formats it into the stored hyphenated shape before
 * hashing.
 */
final class RecoveryCodeNormalizer
{
    /** Phone-readable alphabet - excludes the ambiguous I, L, O, 0 and 1. */
    private const ALLOWED = 'A-HJKMNP-Z2-9';

    public function normalize(string $input): string
    {
        $upper = strtoupper($input);

        return (string) preg_replace('/[^'.self::ALLOWED.']/', '', $upper);
    }
}
