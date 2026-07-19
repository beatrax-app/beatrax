<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Providers;

use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingWizardModal;
use Modules\OpenBanking\Internal\Listeners\RaiseOpenBankingReconsentAlert;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
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
 * 19-06 adds the never-throw reconsent-alert listener.
 *
 * boot() conditionally loads migrations / routes / views — the
 * project-wide convention every module provider in this codebase
 * carries (cloned from `Modules\Position\Providers\PositionServiceProvider`).
 * 19-06 additionally registers the onboarding wizard's Livewire SFC and
 * the `OpenBankingConsentFailed` -> `RaiseOpenBankingReconsentAlert`
 * event wiring (single-owner: no other file in this module registers
 * that listener), mirroring `EmailScanServiceProvider::boot()`'s
 * identical `InboxTokenFailed` wiring. 19-09 additionally binds
 * `RemoteSourceAdapter` to the concrete `EnableBankingSourceAdapter` —
 * the dependency `OpenBankingFetchService` (and, transitively,
 * `SyncOpenBankingAccountJob`) resolves through the interface —
 * and registers `OpenBankingFetchService` as a singleton (stateless:
 * every call reads fresh connection/credential state). Later waves own
 * the scheduler entries and the settings-page wiring; this plan's
 * single-owner discipline forbids adding any of that here yet.
 */
final class OpenBankingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpenBankingSecretsRepository::class);
        $this->app->singleton(EnableBankingJwtSigner::class);
        $this->app->singleton(EnableBankingHttpClient::class);
        $this->app->singleton(OpenBankingStateRepository::class);
        $this->app->singleton(RaiseOpenBankingReconsentAlert::class);
        $this->app->singleton(RemoteSourceAdapter::class, EnableBankingSourceAdapter::class);
        $this->app->singleton(OpenBankingFetchService::class);
    }

    public function boot(LivewireManager $livewire, EventsDispatcher $events): void
    {
        $events->listen(OpenBankingConsentFailed::class, RaiseOpenBankingReconsentAlert::class);

        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'openbanking');
        }

        $livewire->component('openbanking.open-banking-wizard-modal', OpenBankingWizardModal::class);
    }
}
