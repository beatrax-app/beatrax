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

    // Whether protect() genuinely makes the stored bytes unreadable from the
    // file they land in. A caller persisting key material MUST refuse to write
    // it when this is false, rather than assume the binding is a real shield.
    public function protectsAtRest(): bool;
}
