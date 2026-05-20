<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the Auth module.
 *
 * boot() conditionally loads the module's migrations so the
 * username-based user schema, the user_recovery_codes table, and the
 * oauth_secrets table participate in `migrate` / `migrate:fresh`.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
    }
}
