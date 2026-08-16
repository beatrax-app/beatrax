<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Recovery;

// Filename is derived from the owning username, lowercased so it stays
// stable regardless of how the username was originally typed.
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
