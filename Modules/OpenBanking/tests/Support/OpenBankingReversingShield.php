<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Modules\Core\Public\Contracts\SecretShield;

final class OpenBankingReversingShield implements SecretShield
{
    public function protect(string $plaintext): string
    {
        return strrev($plaintext);
    }

    public function reveal(string $shielded): string
    {
        return strrev($shielded);
    }

    public function protectsAtRest(): bool
    {
        return true;
    }
}
