<?php

declare(strict_types=1);

namespace Modules\EmailScan\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\EmlBlobStore;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
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
 * the /inboxes Livewire SFC + the OAuth-client wizard modal SFC. The
 * wizard component handles both the Gmail and Microsoft 365 variants
 * by branching on the $provider property the trigger sets.
 *
 * The JobFailed listener + the top-nav View Factory composer (for the
 * inboxes badge) are wired in later plans.
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
    }

    public function boot(LivewireManager $livewire): void
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
    }
}
