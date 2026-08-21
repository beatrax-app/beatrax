<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Recovery;

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
