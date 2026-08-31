<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Providers;

use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Core\Public\Support\RegistersScheduledCommands;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Console\ServeOpenBankingTlsCommand;
use Modules\OpenBanking\Internal\Console\SyncDueOpenBankingConnectionsCommand;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Internal\Events\OpenBankingImportedNothing;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingWizardModal;
use Modules\OpenBanking\Internal\Listeners\RaiseOpenBankingNothingImportedAlert;
use Modules\OpenBanking\Internal\Listeners\RaiseOpenBankingReconsentAlert;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Modules\OpenBanking\Public\Http\Livewire\OpenBankingStatusRow;

final class OpenBankingServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;
    use RegistersScheduledCommands;

    public function register(): void
    {
        $this->app->singleton(OpenBankingSecretsRepository::class);
        $this->app->singleton(EnableBankingJwtSigner::class);
        $this->app->singleton(EnableBankingHttpClient::class);
        $this->app->singleton(OpenBankingStateRepository::class);
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
        $events->listen(OpenBankingImportedNothing::class, RaiseOpenBankingNothingImportedAlert::class);

        $this->loadModuleResources('openbanking');

        $this->registerScheduledCommands([SyncDueOpenBankingConnectionsCommand::class]);

        $livewire->component('openbanking.open-banking-wizard-modal', OpenBankingWizardModal::class);
        $livewire->component('openbanking.open-banking-settings-page', OpenBankingSettingsPage::class);
        $livewire->component('openbanking.open-banking-status-row', OpenBankingStatusRow::class);

        // The HTTPS-loopback listener is local UAT tooling, guarded so it never
        // binds on a web request.
        if ($this->app->runningInConsole()) {
            $this->commands([ServeOpenBankingTlsCommand::class]);
        }
    }
}
