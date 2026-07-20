<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class SecretsColumnRegistry
{
    // Anchors the noSecretsInLivewireSnapshot arch invariant: every concrete
    // Livewire component is reflection-walked, and any public property,
    // $listeners key, or $queryString entry naming a `{table}.{column}` below
    // is flagged. New entries land here; the list never shrinks without review.
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'oauth_secrets.access_token',
            'oauth_secrets.refresh_token',
            'oauth_secrets.client_secret',
            'users.password',
            'users.remember_token',
            'user_recovery_codes.code_hash',
        ];
    }

    // DI-shim: constructor-injected consumers call this instance method,
    // which delegates to the static accessor above so the canonical list
    // lives in exactly one place.
    /**
     * @return list<string>
     */
    public function all(): array
    {
        return self::columns();
    }
}
