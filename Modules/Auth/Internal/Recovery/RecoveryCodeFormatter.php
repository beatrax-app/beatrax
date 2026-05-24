<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

/**
 * Builds the plaintext `.txt` artefact a user downloads from the
 * recovery-codes display ceremony.
 *
 * The body is the recovery codes joined by newlines — one code per line,
 * with no header row and no trailing newline. The filename is derived
 * from the owning username, lowercased so the file is stable regardless
 * of how the username was originally typed.
 */
final class RecoveryCodeFormatter
{
    /**
     * @param  list<string>  $codes
     */
    public function format(array $codes): string
    {
        return implode("\n", $codes);
    }

    public function filenameFor(string $username): string
    {
        return 'beatrax-recovery-codes-'.strtolower(trim($username)).'.txt';
    }
}
