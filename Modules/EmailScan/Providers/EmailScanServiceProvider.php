<?php

declare(strict_types=1);

namespace Modules\EmailScan\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
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
 * (GoogleOAuthProvider, OAuthStateRepository). All collaborators are
 * stateless and singleton-safe.
 *
 * boot() conditionally loads migrations / routes / views and
 * registers the /inboxes Livewire component. The wizard modal SFC
 * is registered alongside it in a later plan.
 *
 * The Microsoft OAuth provider + the JobFailed listener + the top-nav
 * View Factory composer (for the inboxes badge) are wired in later
 * plans.
 */
final class EmailScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InboxMessageQuery::class);
        $this->app->singleton(OAuthSecretsRepository::class);

        // Wave 2 — OAuth surface + Public read services.
        $this->app->singleton(GoogleOAuthProvider::class);
        $this->app->singleton(OAuthStateRepository::class);
        $this->app->singleton(InboxQuery::class);
        $this->app->singleton(InboxesBadgeCount::class);
        $this->app->singleton(KnownSenderQuery::class);
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

        // Plan 03 — /inboxes page Livewire SFC. The OAuth-client
        // wizard modal SFC registers alongside it in a later step.
        $livewire->component('email-scan.inboxes-page', InboxesPage::class);
    }
}
