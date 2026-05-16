<?php

declare(strict_types=1);

namespace Modules\EmailScan\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Wires the EmailScan module.
 *
 * Wave 0 ships the empty service-provider shell. Singleton bindings for
 * the OAuth secrets repository, Gmail / Microsoft Graph API clients,
 * incremental + backfill + discovery jobs, and the inbox-scan state
 * machine land in later plans. The provider follows the established
 * Chains module shape: register() declares container bindings, boot()
 * conditionally loads migrations / routes / views / Livewire
 * components when each directory exists, so the early waves can stand
 * up scaffolding without each plan needing to amend this file.
 *
 * boot() also intentionally omits the JobFailed listener and the
 * top-nav View Factory composer — both will be wired in the plans that
 * introduce the jobs and the inboxes-badge counter respectively.
 */
final class EmailScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton bindings land in later waves once the OAuth
        // secrets repository, the two API clients, the state machine,
        // and the three queued jobs ship.
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

        // Livewire components register here once the inboxes page +
        // OAuth-client wizard + backfill-window modal ship. The
        // LivewireManager parameter is kept on the boot() signature to
        // match the project-wide module convention so later plans
        // amend the body, not the method signature.
        unset($livewire);
    }
}
