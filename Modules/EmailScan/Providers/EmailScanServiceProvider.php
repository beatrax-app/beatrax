<?php

declare(strict_types=1);

namespace Modules\EmailScan\Providers;

use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\EmailScan\Database\Seeders\IcsStatementSenderSeeder;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\Jobs\DetectIcsStatementReadyJob;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Internal\Jobs\ScanMessageMapper;
use Modules\EmailScan\Internal\Listeners\EmitOAuthReauthRequiredAlert;
use Modules\EmailScan\Internal\Listeners\RaiseReconsentAlertOnTokenFailure;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Actions\DismissDiscoveredSender;
use Modules\EmailScan\Public\Actions\PromoteDiscoveredSender;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxesBadgeCount;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\EmailScan\Public\Services\InboxQuery;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

final class EmailScanServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(InboxMessageQuery::class);
        $this->app->singleton(OAuthSecretsRepository::class);
        $this->app->singleton(LoopbackRedirectUri::class);

        $this->app->singleton(GoogleOAuthProvider::class);
        $this->app->singleton(MicrosoftOAuthProvider::class);
        $this->app->singleton(OAuthStateRepository::class);
        $this->app->singleton(InboxQuery::class);
        $this->app->singleton(InboxesBadgeCount::class);
        $this->app->singleton(KnownSenderQuery::class);
        $this->app->singleton(DiscoveredSenderQuery::class);
        $this->app->singleton(PromoteDiscoveredSender::class);
        $this->app->singleton(DismissDiscoveredSender::class);

        $this->app->singleton(EmlBlobStore::class);
        $this->app->singleton(MimeHeaderParser::class);
        $this->app->singleton(GraphErrorMapper::class);
        $this->app->singleton(ScanMessageMapper::class);
        $this->app->singleton(GmailApiClient::class);
        // The contracts exist so tests can rebind them to the Fake clients
        // via $this->app->instance(...).
        $this->app->singleton(GmailApiClientContract::class, GmailApiClient::class);
        $this->app->singleton(GraphApiClient::class);
        $this->app->singleton(GraphApiClientContract::class, GraphApiClient::class);
        $this->app->singleton(InboxScanStateMachine::class);
        $this->app->singleton(IncrementalScanJob::class);
        $this->app->singleton(DiscoveryScanJob::class);
        $this->app->singleton(EmitOAuthReauthRequiredAlert::class);
        $this->app->singleton(RaiseReconsentAlertOnTokenFailure::class);

        $this->app->singleton(DetectIcsStatementReadyJob::class);
        $this->app->singleton(IcsStatementSenderSeeder::class);
    }

    public function boot(LivewireManager $livewire, EventsDispatcher $events): void
    {
        // Ends in a de-duped system_alerts row the SystemAlertsBanner renders
        // with a Reconnect link back through /inboxes?reconnect={id}.
        $events->listen(InboxTokenFailed::class, RaiseReconsentAlertOnTokenFailure::class);

        $this->loadModuleResources('email-scan');

        // One wizard component for both providers; it branches on $provider.
        $livewire->component('email-scan.inboxes-page', InboxesPage::class);
        $livewire->component('email-scan.oauth-client-wizard-modal', OAuthClientWizardModal::class);
        $livewire->component('email-scan.backfill-window-modal', BackfillWindowModal::class);

        $this->registerTopNavBadgeComposer();
    }

    // Also runs EmitOAuthReauthRequiredAlert on the same per-request hook.
    private function registerTopNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
            $currentUser = $app->make(CurrentUser::class);
            if (! $currentUser->isAuthenticated()) {
                $compose->with('inboxesBadgeCount', 0);

                return;
            }
            $query = $app->make(InboxesBadgeCount::class);
            $compose->with('inboxesBadgeCount', $query->forCurrentUser($currentUser->user()));

            $app->make(EmitOAuthReauthRequiredAlert::class)->handle();
        });
    }
}
