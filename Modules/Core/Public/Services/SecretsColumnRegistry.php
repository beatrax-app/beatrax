<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

/**
 * Single source-of-truth enumerating the database columns the app
 * treats as secret material.
 *
 * The list anchors the noSecretsInLivewireSnapshot arch invariant
 * (tests/Contracts/SecretsInLivewireSnapshotTest.php) — every concrete
 * Livewire component under Modules/.../Http/Livewire/ is reflection-walked
 * and any public property, $listeners key, or $queryString entry whose
 * name references a column listed below is flagged as a wire-snapshot
 * exposure of stored secret data.
 *
 * Entries are namespaced as `{table}.{column}`. The current list covers
 * the OAuth credential triple (used by the email-provider integration),
 * the user password + remember-token columns (Laravel auth), and the
 * recovery-code hash (single-use account recovery codes).
 *
 * Both a static accessor (columns()) and an instance method (all())
 * are provided. DI consumers inject the service and call all(); the
 * arch test calls the static accessor because it runs before the
 * application container is the right thing to reach for.
 */
final class SecretsColumnRegistry
{
    /**
     * Enumerate every column the noSecretsInLivewireSnapshot invariant
     * treats as a secret. New entries land here and the arch test
     * re-fires; the registry never shrinks without explicit review of
     * the historical entries.
     *
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

    /**
     * DI-shim surface — constructor-injected consumers call this
     * instance method, which delegates to the static accessor so the
     * canonical list lives in exactly one place.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return self::columns();
    }
}
