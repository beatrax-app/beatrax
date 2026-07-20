<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Modules\Core\Public\Contracts\SecretShield;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class PassthroughSecretShield implements SecretShield
{
    public function protect(string $plaintext): string
    {
        return $plaintext;
    }

    public function reveal(string $shielded): string
    {
        return $shielded;
    }
}
