<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Modules\Core\Public\Contracts\SecretShield;

/**
 * The default {@see SecretShield} on web / mobile and any build without an
 * OS keychain binding: identity in both directions.
 *
 * With this shield bound, a persisted secret is stored and read exactly as
 * it was before the shield seam existed — still protected by whatever
 * column-level encryption the caller already applies (e.g. the OAuthSecret
 * model's `encrypted` cast). Only the desktop bundle overrides this binding
 * to add the safeStorage layer.
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
