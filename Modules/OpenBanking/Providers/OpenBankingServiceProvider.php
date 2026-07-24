<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Providers;

use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Console\ServeOpenBankingTlsCommand;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingStatusRow;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingWizardModal;
use Modules\OpenBanking\Internal\Listeners\RaiseOpenBankingReconsentAlert;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

/**
 * @link ../../../.docs/features/open-banking/architecture.md
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
        $this->app->singleton(OpenBankingConnectionQuery::class);
        $this->app->singleton(
            LoopbackTlsCertificate::class,
            static fn (): LoopbackTlsCertificate => new LoopbackTlsCertificate(UserDataPathService::appPath('open-banking-tls')),
        );
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
        $livewire->component('openbanking.open-banking-settings-page', OpenBankingSettingsPage::class);
        $livewire->component('openbanking.open-banking-status-row', OpenBankingStatusRow::class);

        // Local dev/UAT only: the HTTPS-loopback TLS listener that lets the
        // Enable Banking consent dance run against the https://127.0.0.1:PORT
        // redirect URI. Guarded so it never binds on web requests.
        if ($this->app->runningInConsole()) {
            $this->commands([ServeOpenBankingTlsCommand::class]);
        }
    }
}
