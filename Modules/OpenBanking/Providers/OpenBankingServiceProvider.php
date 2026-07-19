<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the OpenBanking module.
 *
 * This is a Wave 0 scaffold-only provider: register()/boot() are
 * intentionally empty beyond the conditional migrations/routes/views
 * loading every module provider in this codebase carries (cloned from
 * `Modules\Position\Providers\PositionServiceProvider`). Later waves own
 * wiring the secrets repository, HTTP client, consent controllers, and
 * scheduler entries — this plan's single-owner discipline forbids adding
 * any of that here yet.
 */
final class OpenBankingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'openbanking');
        }
    }
}
