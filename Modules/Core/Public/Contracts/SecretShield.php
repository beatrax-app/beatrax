<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

interface SecretShield
{
    /**
     * @see self::reveal()
     */
    public function protect(string $plaintext): string;

    /**
     * @see self::protect()
     */
    public function reveal(string $shielded): string;
}
