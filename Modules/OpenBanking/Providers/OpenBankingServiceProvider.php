<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

/**
 * Wires the OpenBanking module.
 *
 * register() declares singleton bindings for the consent-dance
 * collaborators (19-05): the file-backed secrets repository, the SSRF-
 * guarded Enable Banking HTTP client + its JWT signer, and the
 * per-flow CSRF state repository the connect/callback controllers
 * consume. All are stateless and singleton-safe (each request reads
 * fresh state from disk/session rather than caching in memory).
 *
 * boot() conditionally loads migrations / routes / views — the
 * project-wide convention every module provider in this codebase
 * carries (cloned from `Modules\Position\Providers\PositionServiceProvider`).
 * Later waves own the scheduler entries and the settings-page wiring;
 * this plan's single-owner discipline forbids adding any of that here
 * yet.
 */
final class OpenBankingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpenBankingSecretsRepository::class);
        $this->app->singleton(EnableBankingJwtSigner::class);
        $this->app->singleton(EnableBankingHttpClient::class);
        $this->app->singleton(OpenBankingStateRepository::class);
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
