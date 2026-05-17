<?php

declare(strict_types=1);

namespace Modules\EmailScan\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\EmlBlobStore;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Actions\DismissDiscoveredSender;
use Modules\EmailScan\Public\Actions\PromoteDiscoveredSender;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
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
 * boot() conditionally loads migrations / routes / views, registers
 * the /inboxes Livewire SFC + the OAuth-client wizard modal SFC + the
 * backfill-window modal SFC, and subscribes a JobFailed listener that
 * flips inbox_scan_state.status to 'error' when a BackfillInboxJob or
 * IncrementalScanJob exhausts the queue worker's retry budget. The
 * listener resolves the inbox_id from the failed job's serialised
 * payload and dispatches through InboxScanStateMachine — the sole
 * legal mutator of the status column (BoundaryArchTest invariant).
 *
 * Subscribing through the injected Dispatcher rather than the
 * `Queue::failing` facade keeps the CLAUDE.md "DI-only" posture
 * intact — the Queue / Cache facade pair is reserved for
 * `Cache::driver('redis')` inside the queued jobs' uniqueVia(),
 * which is the only permitted facade call in module code
 * (BoundaryArchTest carve-out).
 *
 * The top-nav View Factory composer (for the inboxes badge) lands in
 * Plan 08 when the dashboard tile + reauth toast UI ships.
 */
final class EmailScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InboxMessageQuery::class);
        $this->app->singleton(OAuthSecretsRepository::class);

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
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
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

        $this->registerJobFailedListener($events);
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
        });
    }

    /**
     * Flip inbox_scan_state.status to 'error' when a BackfillInboxJob
     * or IncrementalScanJob exhausts the queue worker's retry budget.
     *
     * The job's serialised payload carries the inbox_id as a readonly
     * public property; a defensive regex extracts the integer value
     * across both the compact `i:` and the named-arg serialiser
     * shapes (mirrors ChainsServiceProvider::extractUserIdFromFailedJob).
     *
     * The transition is funnelled through InboxScanStateMachine so the
     * sole-mutator invariant (BoundaryArchTest
     * noOtherInboxScanStateMutator) holds. Invalid transitions —
     * e.g. an already-needs_reauth inbox that fails again — are
     * swallowed so the listener never escalates a recovery scenario
     * into a hard error.
     */
    private function registerJobFailedListener(Dispatcher $events): void
    {
        $app = $this->app;

        $events->listen(JobFailed::class, static function (JobFailed $event) use ($app): void {
            $jobName = $event->job->resolveName();
            $isEmailScanJob = str_contains($jobName, 'BackfillInboxJob')
                || str_contains($jobName, 'IncrementalScanJob');
            if (! $isEmailScanJob) {
                return;
            }

            $inboxId = self::extractInboxIdFromFailedJob($event);
            if ($inboxId === null) {
                return;
            }

            try {
                $sm = $app->make(InboxScanStateMachine::class);
                $sm->applyStatus(
                    $inboxId,
                    'error',
                    substr($event->exception->getMessage(), 0, 500),
                );
            } catch (\Throwable) {
                // Swallow — an invalid transition (e.g. the inbox is
                // already in needs_reauth which only permits → idle)
                // must not escalate a job failure into a server error
                // in the queue worker's failed-job hook.
            }
        });
    }

    /**
     * Pull the inboxId out of the failed job's serialised payload. The
     * job classes (BackfillInboxJob + IncrementalScanJob) both declare
     * `public readonly int $inboxId` so the serialised representation
     * includes an `inboxId` property; the regex isolates the integer
     * value across both the compact `i:` and the named-arg serialiser
     * shapes.
     */
    private static function extractInboxIdFromFailedJob(JobFailed $event): ?int
    {
        $payload = $event->job->payload();
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }
        $command = $data['command'] ?? null;
        if (! is_string($command)) {
            return null;
        }
        if (preg_match('/inboxId[^0-9-]+(-?\d+)/', $command, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
