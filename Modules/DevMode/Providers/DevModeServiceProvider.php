<?php

declare(strict_types=1);

namespace Modules\DevMode\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Audit\RedactionExcerptCap;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\CommandRegistry;
use Modules\DevMode\Internal\Console\PruneDevAuditCommand;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;
use Modules\DevMode\Internal\Http\Livewire\TripleGateModal;
use Modules\DevMode\Internal\Http\Middleware\EnsureDeveloperMode;
use Modules\DevMode\Internal\Listeners\BustOAuthScrubSetOnSecretChange;
use Modules\DevMode\Internal\Listeners\ResetAdvancedToggleOnLogin;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Internal\Registries\NullAppActionRegistry;
use Modules\DevMode\Internal\Registries\NullNavigationRegistry;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;
use Modules\EmailScan\Models\OAuthSecret;
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * Wires the DevMode module.
 *
 * `register()` binds the four Public contracts to their default Null*
 * concretes so consumer code can resolve the contracts from day one:
 *
 *   - DevCommandRegistry → NullDevCommandRegistry (replaced in 16-04
 *     by DevCommandRegistryImpl which hard-codes the CONTEXT D-12 +
 *     D-13 allow-lists)
 *   - NavigationRegistry → NullNavigationRegistry (replaced in 16-08
 *     by NavigationRegistryImpl)
 *   - AppActionRegistry → NullAppActionRegistry (replaced in 16-08
 *     by AppActionRegistryImpl)
 *   - AuditWriter → NullAuditWriter (replaced in 16-04 by
 *     SpatieAuditWriter, which routes through
 *     spatie/laravel-activitylog into the renamed `dev_mode_audit`
 *     table per CONTEXT D-23 + D-24)
 *
 * Later plans REPLACE the binding from their own ServiceProviders;
 * the Null* shape is the default so no consumer needs an
 * `app()->bound(...)` null-check.
 *
 * `boot()` registers the `ensureDeveloperMode` middleware alias (the
 * sole gate on `/dev/*`), loads the module's migrations + routes +
 * views, and registers the dev-shell-mounted Livewire components.
 *
 * Per CLAUDE.md DI-only carve-out: no facade calls anywhere in this
 * provider; the Router collaborator is method-injected via the
 * framework's container.
 */
final class DevModeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CONTEXT D-12 SAFE-tier + D-13 DESTRUCTIVE-tier roster.
        // CONTEXT D-14 NEVER-EXPOSED commands (migrate, migrate:rollback,
        // db:seed) are deliberately absent from this list — the spawner
        // whitelists against CommandRegistry::find() so any attempt to
        // spawn one of them throws InvalidArgumentException before a
        // Process is constructed.
        $this->app->singleton(DevCommandRegistry::class, static fn (): CommandRegistry => new CommandRegistry([
            // ---- SAFE TIER (CONTEXT D-12) ----
            new CommandSpec(
                name: 'db:backup',
                label: 'Back up database',
                tier: 'safe',
                argsSchema: [
                    new ArgSpec(
                        name: 'destination',
                        label: 'Destination file',
                        type: 'file-path',
                        rules: ['nullable', 'string', 'max:1024'],
                        placeholder: '/path/to/backup.sqlite (optional)',
                        helpText: 'Leave blank to use the default backups directory.',
                    ),
                ],
                description: 'Write a timestamped SQLite copy to the backups directory (or the given path).',
            ),
            new CommandSpec(
                name: 'beatrax:doctor',
                label: 'Run doctor',
                tier: 'safe',
                argsSchema: [],
                description: 'Report installed PHP / Composer / SQLite versions and verify minimums.',
            ),
            new CommandSpec(
                name: 'beatrax:failed-jobs prune',
                label: 'Prune failed jobs',
                tier: 'safe',
                argsSchema: [],
                description: 'Prune resolved entries from the Laravel-managed failed_jobs table.',
            ),
            new CommandSpec(
                name: 'cache:clear',
                label: 'Clear cache',
                tier: 'safe',
                argsSchema: [],
                description: 'Flush the application cache store.',
            ),
            new CommandSpec(
                name: 'route:list',
                label: 'List routes',
                tier: 'safe',
                argsSchema: [],
                description: 'Print every registered HTTP route to stdout.',
            ),
            new CommandSpec(
                name: 'config:show',
                label: 'Show config',
                tier: 'safe',
                argsSchema: [
                    new ArgSpec(
                        name: 'name',
                        label: 'Config key',
                        type: 'text',
                        rules: ['nullable', 'string', 'max:255'],
                        placeholder: 'app.name (optional)',
                        helpText: 'Leave blank to dump every config key.',
                    ),
                ],
                description: 'Print the value at the given dotted config key (or the full tree).',
            ),
            new CommandSpec(
                name: 'view:clear',
                label: 'Clear view cache',
                tier: 'safe',
                argsSchema: [],
                description: 'Flush the compiled Blade-view cache.',
            ),
            new CommandSpec(
                name: 'queue:retry',
                label: 'Retry failed jobs',
                tier: 'safe',
                argsSchema: [
                    new ArgSpec(
                        name: 'id',
                        label: 'Job id',
                        type: 'text',
                        rules: ['nullable', 'string', 'max:64'],
                        placeholder: 'all (or a specific id)',
                        helpText: 'Leave blank to retry every failed job; pass an id to retry a single entry.',
                    ),
                    new ArgSpec(
                        name: '--queue',
                        label: 'Queue name',
                        type: 'text',
                        rules: ['nullable', 'string', 'max:255'],
                        placeholder: 'default',
                        helpText: 'Optional queue filter; defaults to all queues.',
                    ),
                ],
                description: 'Retry one (by id) or every (blank id) failed job.',
            ),
            new CommandSpec(
                name: 'beatrax:rederive-fingerprints',
                label: 'Rederive fingerprints',
                tier: 'safe',
                argsSchema: [],
                description: 'Re-compute every transaction fingerprint using the current normalization version.',
            ),

            // ---- DESTRUCTIVE TIER (CONTEXT D-13) — runner-only; triple-gate enforced in 16-04b ----
            new CommandSpec(
                name: 'db:restore',
                label: 'Restore database',
                tier: 'destructive',
                argsSchema: [
                    new ArgSpec(
                        name: 'from',
                        label: 'Backup file path',
                        type: 'file-path',
                        rules: ['required', 'string', 'max:1024'],
                        placeholder: '/path/to/backup.sqlite',
                        helpText: 'Replaces the current database with the file at the given path.',
                    ),
                ],
                description: 'Replace the current database with the given backup file.',
            ),
            new CommandSpec(
                name: 'migrate:fresh',
                label: 'Drop tables and re-migrate',
                tier: 'destructive',
                argsSchema: [],
                description: 'Drop every table, then re-run every migration.',
            ),
            new CommandSpec(
                name: 'beatrax:reset-password',
                label: 'Reset password',
                tier: 'destructive',
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        label: 'Username',
                        type: 'text',
                        rules: ['required', 'string', 'max:64'],
                        placeholder: 'alice',
                    ),
                ],
                description: 'Interactively reset a user password (refuses non-interactive use).',
            ),
            new CommandSpec(
                name: 'beatrax:regenerate-recovery-codes',
                label: 'Regenerate recovery codes',
                tier: 'destructive',
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        label: 'Username',
                        type: 'text',
                        rules: ['required', 'string', 'max:64'],
                        placeholder: 'alice',
                    ),
                ],
                description: 'Regenerate the 10 single-use recovery codes for a user.',
            ),
            new CommandSpec(
                name: 'beatrax:grant-dev',
                label: 'Grant developer access',
                tier: 'destructive',
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        label: 'Username',
                        type: 'text',
                        rules: ['required', 'string', 'max:64'],
                        placeholder: 'alice',
                    ),
                ],
                description: 'Set is_developer=true for the given user.',
            ),
            new CommandSpec(
                name: 'beatrax:install',
                label: 'Run install',
                tier: 'destructive',
                argsSchema: [],
                description: 'Idempotent first-run setup. Re-running on a configured install is destructive.',
            ),
        ]));

        $this->app->singleton(RunRegistry::class, static fn (Application $app): RunRegistry => new RunRegistry(
            $app->make(CacheRepository::class),
            $app->make(Clock::class),
        ));

        $this->app->singleton(FileTailer::class, static fn (): FileTailer => new FileTailer);

        $this->app->singleton(CommandSpawner::class, static fn (Application $app): CommandSpawner => new CommandSpawner(
            $app->make(RunRegistry::class),
            $app->make(Clock::class),
            $app->make(DevCommandRegistry::class),
        ));

        $this->app->singleton(NavigationRegistry::class, NullNavigationRegistry::class);
        $this->app->singleton(AppActionRegistry::class, NullAppActionRegistry::class);

        // 16-05 — OAuthScrubSet singleton. Lazily loads the decrypted
        // `oauth_secrets.client_secret` + every string in `tokens_blob`
        // on first `all()` / `compiledPattern()` call. The Eloquent
        // observer registered in boot() busts this cache on every
        // OAuthSecret save/delete so a rotated secret applies on the
        // very next log line / audit excerpt write.
        $this->app->singleton(OAuthScrubSet::class, static fn (): OAuthScrubSet => new OAuthScrubSet);

        // 16-05 — RedactionExcerptCap UPGRADED in place. Constructor now
        // takes the OAuthScrubSet singleton so the audit-row excerpt
        // scrubs every oauth_secret literal before the Bearer + JWT
        // pattern. SpatieAuditWriter is unchanged — it resolves the cap
        // via this container singleton.
        $this->app->singleton(RedactionExcerptCap::class, static fn (Application $app): RedactionExcerptCap => new RedactionExcerptCap(
            $app->make(OAuthScrubSet::class),
        ));

        // 16-05 — RedactSecretsProcessor UPGRADED in place. PushRedactProcessor
        // resolves THIS singleton on every channel boot via the
        // container so the constructor-DI change propagates without
        // touching PushRedactProcessor or config/logging.php. The
        // baseline test (which `new`s the class directly) keeps working
        // because the OAuthScrubSet constructor arg is nullable.
        $this->app->singleton(RedactSecretsProcessor::class, static fn (Application $app): RedactSecretsProcessor => new RedactSecretsProcessor(
            $app->make(OAuthScrubSet::class),
        ));

        $this->app->singleton(AuditWriter::class, static fn (Application $app): SpatieAuditWriter => new SpatieAuditWriter(
            $app->make(CurrentUser::class),
            $app->make(Clock::class),
            $app->make(RedactionExcerptCap::class),
            $app->make(ActivityLogger::class),
        ));

        $this->app->singleton(FinalizeRunAudit::class, static fn (Application $app): FinalizeRunAudit => new FinalizeRunAudit(
            $app->make(AuditWriter::class),
            $app->make(RunRegistry::class),
            $app->make(Clock::class),
        ));
    }

    public function boot(Router $router, LivewireManager $livewire, Dispatcher $events): void
    {
        $router->aliasMiddleware('ensureDeveloperMode', EnsureDeveloperMode::class);

        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dev');
        }

        $livewire->component('dev.overview-page', DevOverviewPage::class);
        $livewire->component('dev.triple-gate-modal', TripleGateModal::class);
        $livewire->component('dev.artisan-runner-page', ArtisanRunnerPage::class);
        $livewire->component('dev.audit-log-page', AuditLogPage::class);
        $livewire->component('dev.log-tailer-page', LogTailerPage::class);

        // W-8 FIX: register the queue-worker heartbeat via the
        // QueueManager::looping(closure) form. The event-listener form
        // (Looping::class) does NOT reliably fire under Laravel 13's
        // queue:work — only the closure-callbacks do. The closure
        // resolves WriteWorkerHeartbeat from the container on every
        // tick so its DI dependencies (Clock, CacheRepository) stay
        // bound to the latest container singletons.
        /** @var QueueManager $queueManager */
        $queueManager = $this->app->make(QueueManager::class);
        $appLocal = $this->app;
        $queueManager->looping(static function () use ($appLocal): void {
            /** @var WriteWorkerHeartbeat $heartbeat */
            $heartbeat = $appLocal->make(WriteWorkerHeartbeat::class);
            ($heartbeat)();
        });

        // CONTEXT D-20 — the Advanced toggle resets to OFF on every
        // successful Login. The runner page's mount() resets a SECOND
        // time on first-load-per-session as a belt-and-braces.
        $events->listen(Login::class, [ResetAdvancedToggleOnLogin::class, 'handle']);

        // CONTEXT D-30 — OAuthScrubSet cache invalidation. Attach the
        // Eloquent observer to OAuthSecret so the scrub set busts on
        // every save/delete; the next log line / audit row's
        // compiledPattern() lazy-rebuilds from the live table. The
        // observer is resolved through the container so it picks up
        // the singleton OAuthScrubSet.
        OAuthSecret::observe(BustOAuthScrubSetOnSecretChange::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneDevAuditCommand::class,
            ]);
        }
    }
}
