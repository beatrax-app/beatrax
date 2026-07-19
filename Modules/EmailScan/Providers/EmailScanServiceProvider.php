<?php

declare(strict_types=1);

namespace Modules\EmailScan\Providers;

use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Database\Seeders\IcsStatementSenderSeeder;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\Jobs\DetectIcsStatementReadyJob;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
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

/**
 * Wires the EmailScan module.
 *
 * register() declares singleton bindings for the Public read services
 * (InboxQuery, KnownSenderQuery, InboxMessageQuery, InboxesBadgeCount,
 * OAuthSecretsRepository) and the Internal OAuth surface
 * (GoogleOAuthProvider, MicrosoftOAuthProvider, OAuthStateRepository).
 * All collaborators are stateless and singleton-safe.
 *
 * boot() conditionally loads migrations / routes / views and registers
 * the /inboxes Livewire SFC + the OAuth-client wizard modal SFC + the
 * backfill-window modal SFC, plus the top-nav badge View Factory
 * composer.
 *
 * Failed-job lifecycle: each of BackfillInboxJob + IncrementalScanJob
 * defines its own `failed(Throwable, InboxScanStateMachine)` method;
 * Laravel resolves the InboxScanStateMachine via container DI and
 * the job flips its own inbox_scan_state.status to 'error' with the
 * truncated exception message. The state machine remains the sole
 * mutator of the status column (BoundaryArchTest invariant). Per-job
 * failed() hooks tie failure handling to the typed job class itself,
 * which keeps the lookup independent of Laravel's serialised-payload
 * format.
 *
 * The queued jobs' `uniqueVia()` callbacks resolve their lock store
 * through `Modules\Core\Public\Support\LockStore::forUniqueJobs()`,
 * the single sanctioned `Cache` facade caller (BoundaryArchTest
 * carve-out).
 */
final class EmailScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The `email-scan` config (with the OAUTH_LOOPBACK_PORT
        // env-var override) lives at config/email-scan.php at the
        // project root so it is picked up by Laravel's automatic
        // config-directory loader. Keeping the env() call inside
        // config/ ensures `config:cache` resolves the value at cache
        // time instead of returning null at runtime.

        $this->app->singleton(InboxMessageQuery::class);
        $this->app->singleton(OAuthSecretsRepository::class);
        $this->app->singleton(LoopbackRedirectUri::class);

        // OAuth surface + Public read services.
        $this->app->singleton(GoogleOAuthProvider::class);
        $this->app->singleton(MicrosoftOAuthProvider::class);
        $this->app->singleton(OAuthStateRepository::class);
        $this->app->singleton(InboxQuery::class);
        $this->app->singleton(InboxesBadgeCount::class);
        $this->app->singleton(KnownSenderQuery::class);
        $this->app->singleton(DiscoveredSenderQuery::class);
        $this->app->singleton(PromoteDiscoveredSender::class);
        $this->app->singleton(DismissDiscoveredSender::class);

        // Internal fetch + persistence collaborators consumed by the
        // backfill / incremental-scan job pipeline.
        $this->app->singleton(EmlBlobStore::class);
        $this->app->singleton(MimeHeaderParser::class);
        $this->app->singleton(GmailApiClient::class);
        // Tests rebind the contract to FakeGmailApiClient via
        // $this->app->instance(GmailApiClientContract::class, ...).
        $this->app->singleton(GmailApiClientContract::class, GmailApiClient::class);
        $this->app->singleton(GraphApiClient::class);
        // Same Fake/real swap pattern for the Graph contract — tests
        // rebind GraphApiClientContract to FakeGraphApiClient via
        // $this->app->instance(...).
        $this->app->singleton(GraphApiClientContract::class, GraphApiClient::class);
        $this->app->singleton(InboxScanStateMachine::class);
        $this->app->singleton(IncrementalScanJob::class);
        $this->app->singleton(DiscoveryScanJob::class);
        $this->app->singleton(EmitOAuthReauthRequiredAlert::class);
        $this->app->singleton(RaiseReconsentAlertOnTokenFailure::class);

        // Req 14 (D-14/D-15): the ICS "statement ready" nudge detector +
        // its companion system-known-sender seeder.
        $this->app->singleton(DetectIcsStatementReadyJob::class);
        $this->app->singleton(IcsStatementSenderSeeder::class);
    }

    public function boot(LivewireManager $livewire, EventsDispatcher $events): void
    {
        // Per-event listener wiring: a refreshed-token failure inside
        // GmailApiClient / GraphApiClient raises InboxTokenFailed, which
        // RaiseReconsentAlertOnTokenFailure picks up to write a single
        // de-duped system_alerts row of kind oauth_reconsent_required.
        // The SystemAlertsBanner then renders the row with a Reconnect
        // link routing back through `/inboxes?reconnect={inbox_id}`.
        $events->listen(InboxTokenFailed::class, RaiseReconsentAlertOnTokenFailure::class);

        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'email-scan');
        }

        // /inboxes page Livewire SFC + the OAuth-client wizard modal
        // SFC (single component, branches on the $provider property
        // to render the Gmail or Microsoft 365 variant).
        $livewire->component('email-scan.inboxes-page', InboxesPage::class);
        $livewire->component('email-scan.oauth-client-wizard-modal', OAuthClientWizardModal::class);
        $livewire->component('email-scan.backfill-window-modal', BackfillWindowModal::class);

        $this->registerTopNavBadgeComposer();
    }

    /**
     * Inject the top-nav "Inboxes" badge integer into the
     * `core::livewire.top-nav` view via the View Factory contract.
     *
     * Mirrors ChainsServiceProvider::registerTopNavBadgeComposer
     * (issue #12 fix in Phase 5): resolves the View Factory contract
     * through `$this->app->make()` so the CLAUDE.md DI-only invariant
     * stays visible at the call site — the `view()` global helper is
     * never used.
     *
     * The composer fires only when the view is actually rendered —
     * meaning at most once per HTTP request that surfaces the top-nav.
     * Each composer invocation reads `CurrentUser` per-request (never
     * caches across requests) and runs a single InboxesBadgeCount query
     * (one COUNT on discovered_senders + one COUNT on inbox_scan_state,
     * both filtered by user_id).
     *
     * The same composer runs the EmitOAuthReauthRequiredAlert listener:
     * it is the per-request hook closest to "the user is looking at the
     * app", and the listener's own .bak-file pre-check plus de-dup guard
     * keep it a no-op on every request after the first.
     */
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
