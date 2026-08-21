<?php

declare(strict_types=1);

namespace Modules\DevMode\Providers;

use App\Providers\HorizonServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Audit\RedactionExcerptCap;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\CommandRegistry;
use Modules\DevMode\Internal\Console\PruneDevAuditCommand;
use Modules\DevMode\Internal\Enums\ArgType;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Http\Livewire\CommandArgPromptModal;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;
use Modules\DevMode\Internal\Http\Livewire\DoctorPanelPage;
use Modules\DevMode\Internal\Http\Livewire\HorizonFramePage;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Http\Livewire\SqlPanelPage;
use Modules\DevMode\Internal\Http\Livewire\SystemSnapshotPage;
use Modules\DevMode\Internal\Http\Livewire\TripleGateModal;
use Modules\DevMode\Internal\Http\Middleware\EnsureDeveloperMode;
use Modules\DevMode\Internal\Http\Middleware\HorizonFrameAncestors;
use Modules\DevMode\Internal\Listeners\BustOAuthScrubSetOnSecretChange;
use Modules\DevMode\Internal\Listeners\LogQueueLifecycle;
use Modules\DevMode\Internal\Listeners\ResetAdvancedToggleOnLogin;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Navigation\AppActionRegistryImpl;
use Modules\DevMode\Internal\Navigation\DevSidebarItems;
use Modules\DevMode\Internal\Navigation\NavigationRegistryImpl;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Internal\Queue\QueueRowLoader;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\AppAction;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;
use Modules\DevMode\Public\Dto\NavigationEntry;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\Shell\Public\Navigation\AppNavigation;
use Modules\Shell\Public\Navigation\ResolvedDestination;
use Spatie\Activitylog\Support\ActivityLogger;

final class DevModeServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->registerCommandRegistry();
        $this->registerProcessServices();
        $this->registerNavigationRegistry();
        $this->registerAppActionRegistry();
        $this->registerRedactionServices();
        $this->registerAuditServices();
        $this->registerQueueServices();
    }

    private function registerQueueServices(): void
    {
        $this->app->singleton(QueueRowLoader::class, static fn (Application $app): QueueRowLoader => new QueueRowLoader(
            $app->make(DatabaseManager::class),
        ));
    }

    // migrate, migrate:rollback and db:seed are deliberately absent: the
    // spawner whitelists against find(), so spawning one throws.
    private function registerCommandRegistry(): void
    {
        $this->app->singleton(DevCommandRegistry::class, static fn (): CommandRegistry => new CommandRegistry([
            ...self::safeCommands(),
            ...self::destructiveCommands(),
        ]));
    }

    /**
     * @return list<CommandSpec>
     */
    private static function safeCommands(): array
    {
        return [
            new CommandSpec(
                name: 'db:backup',
                label: 'Back up database',
                tier: CommandTier::Safe,
                argsSchema: [
                    new ArgSpec(
                        name: 'destination',
                        label: 'Destination file',
                        type: ArgType::FilePath,
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
                tier: CommandTier::Safe,
                argsSchema: [],
                description: 'Report installed PHP / Composer / SQLite versions and verify minimums.',
            ),
            new CommandSpec(
                name: 'beatrax:failed-jobs',
                label: 'Prune failed jobs',
                tier: CommandTier::Safe,
                argsSchema: [
                    new ArgSpec(
                        name: 'action',
                        label: 'Action',
                        type: ArgType::Select,
                        rules: ['required', 'in:prune'],
                        options: ['prune'],
                    ),
                ],
                description: 'Prune resolved entries from the Laravel-managed failed_jobs table.',
            ),
            new CommandSpec(
                name: 'cache:clear',
                label: 'Clear cache',
                tier: CommandTier::Safe,
                argsSchema: [],
                description: 'Flush the application cache store.',
            ),
            new CommandSpec(
                name: 'route:list',
                label: 'List routes',
                tier: CommandTier::Safe,
                argsSchema: [],
                description: 'Print every registered HTTP route to stdout.',
            ),
            new CommandSpec(
                name: 'config:show',
                label: 'Show config',
                tier: CommandTier::Safe,
                argsSchema: [
                    // config:show's positional argument is required upstream;
                    // a no-arg invocation aborts Symfony Console.
                    new ArgSpec(
                        name: 'config',
                        label: 'Config key',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:255'],
                        placeholder: 'app.name',
                        helpText: 'The config file or dotted key to print, e.g. `app` or `database.connections.sqlite`.',
                    ),
                ],
                description: 'Print the value at the given dotted config key.',
            ),
            new CommandSpec(
                name: 'view:clear',
                label: 'Clear view cache',
                tier: CommandTier::Safe,
                argsSchema: [],
                description: 'Flush the compiled Blade-view cache.',
            ),
            new CommandSpec(
                name: 'queue:retry',
                label: 'Retry failed jobs',
                tier: CommandTier::Safe,
                argsSchema: [
                    new ArgSpec(
                        name: 'id',
                        label: 'Job id',
                        type: ArgType::Text,
                        rules: ['nullable', 'string', 'max:64'],
                        placeholder: 'all (or a specific id)',
                        helpText: 'Leave blank to retry every failed job; pass an id to retry a single entry.',
                    ),
                    new ArgSpec(
                        name: '--queue',
                        label: 'Queue name',
                        type: ArgType::Text,
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
                tier: CommandTier::Safe,
                argsSchema: [],
                description: 'Re-compute every transaction fingerprint using the current normalization version.',
            ),
        ];
    }

    /**
     * @return list<CommandSpec>
     */
    private static function destructiveCommands(): array
    {
        return [
            new CommandSpec(
                name: 'db:restore',
                label: 'Restore database',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'from',
                        label: 'Backup file path',
                        type: ArgType::FilePath,
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
                tier: CommandTier::Destructive,
                argsSchema: [],
                description: 'Drop every table, then re-run every migration.',
            ),
            new CommandSpec(
                name: 'beatrax:reset-password',
                label: 'Reset password',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        label: 'Username',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:64'],
                        placeholder: 'alice',
                    ),
                ],
                description: 'Interactively reset a user password (refuses non-interactive use).',
            ),
            new CommandSpec(
                name: 'beatrax:regenerate-recovery-codes',
                label: 'Regenerate recovery codes',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        label: 'Username',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:64'],
                        placeholder: 'alice',
                    ),
                ],
                description: 'Regenerate the 10 single-use recovery codes for a user.',
            ),
            new CommandSpec(
                name: 'beatrax:grant-dev',
                label: 'Grant developer access',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        label: 'Username',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:64'],
                        placeholder: 'alice',
                    ),
                ],
                description: 'Set is_developer=true for the given user.',
            ),
            new CommandSpec(
                name: 'beatrax:install',
                label: 'Run install',
                tier: CommandTier::Destructive,
                argsSchema: [],
                description: 'Idempotent first-run setup. Re-running on a configured install is destructive.',
            ),
        ];
    }

    private function registerProcessServices(): void
    {
        $this->app->singleton(RunRegistry::class, static fn (Application $app): RunRegistry => new RunRegistry(
            $app->make(CacheRepository::class),
            $app->make(Clock::class),
        ));

        $this->app->singleton(FileTailer::class, static fn (): FileTailer => new FileTailer);

        $this->app->singleton(CommandSpawner::class, static fn (Application $app): CommandSpawner => new CommandSpawner(
            $app->make(RunRegistry::class),
            $app->make(Clock::class),
            $app->make(DevCommandRegistry::class),
            $app->make(AuditWriter::class),
        ));

        $this->app->singleton(DevSidebarItems::class, static fn (): DevSidebarItems => new DevSidebarItems);
    }

    // Route names resolve inside the closure, not at register() time, so every
    // module has registered its routes before the lookup. Bound rather than
    // shared: the labels are translated, so a resolution cached for the process
    // would serve the first request's locale to every later one.
    private function registerNavigationRegistry(): void
    {
        $this->app->bind(NavigationRegistry::class, static function (Application $app): NavigationRegistryImpl {
            $router = $app->make(Router::class);

            return new NavigationRegistryImpl(array_merge(
                self::appNavEntries(),
                self::navEntries($router, self::devNavRows()),
            ));
        });
    }

    // The user's own destinations are the Shell module's roster, the same one
    // the sidebar renders, so the palette cannot be missing a screen the rail
    // offers.
    /**
     * @return list<NavigationEntry>
     */
    private static function appNavEntries(): array
    {
        return array_map(
            static fn (ResolvedDestination $destination): NavigationEntry => new NavigationEntry(
                id: $destination->id->value,
                label: $destination->label,
                hint: $destination->hint,
                icon: $destination->icon,
                url: $destination->path,
                keywords: $destination->keywords,
            ),
            AppNavigation::destinations(),
        );
    }

    /**
     * @param  list<array{id: string, label: string, hint: string, icon: string, route: string, keywords: list<string>}>  $rows
     * @return list<NavigationEntry>
     */
    private static function navEntries(Router $router, array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $url = self::resolveRouteUrl($router, $row['route']);
            if ($url === null) {
                continue;
            }
            $entries[] = new NavigationEntry(
                id: $row['id'],
                label: $row['label'],
                hint: $row['hint'],
                icon: $row['icon'],
                url: $url,
                keywords: $row['keywords'],
            );
        }

        return $entries;
    }

    // CommandPaletteModal::buildRegistry() filters these out for
    // non-developers, on top of the route middleware.
    /**
     * @return list<array{id: string, label: string, hint: string, icon: string, route: string, keywords: list<string>}>
     */
    private static function devNavRows(): array
    {
        return [
            ['id' => 'dev.overview', 'label' => 'Dev Overview', 'hint' => 'System tiles + recent runs', 'icon' => '›_', 'route' => 'dev.overview', 'keywords' => ['dev', 'console']],
            ['id' => 'dev.artisan', 'label' => 'Artisan runner', 'hint' => 'Run whitelisted commands', 'icon' => '›_', 'route' => 'dev.artisan', 'keywords' => ['command', 'cli']],
            ['id' => 'dev.audit', 'label' => 'Dev audit log', 'hint' => 'Every dev-mode action', 'icon' => '⌗', 'route' => 'dev.audit', 'keywords' => ['history', 'activity']],
            ['id' => 'dev.logs', 'label' => 'Log tailer', 'hint' => 'Live laravel-*.log stream', 'icon' => '≡', 'route' => 'dev.logs', 'keywords' => ['tail', 'errors']],
            ['id' => 'dev.queue', 'label' => 'Queue inspector', 'hint' => 'Pending / failed / batches', 'icon' => '↻', 'route' => 'dev.queue', 'keywords' => ['jobs', 'failed', 'batches']],
            ['id' => 'dev.doctor', 'label' => 'Doctor', 'hint' => 'System probes', 'icon' => '⚙', 'route' => 'dev.doctor', 'keywords' => ['probes', 'diagnose']],
            ['id' => 'dev.sql', 'label' => 'SQL panel', 'hint' => 'SELECT-only browser', 'icon' => '⌕', 'route' => 'dev.sql', 'keywords' => ['query', 'schema']],
            ['id' => 'dev.system', 'label' => 'System snapshot', 'hint' => 'Env + paths + config', 'icon' => '◇', 'route' => 'dev.system', 'keywords' => ['env', 'config']],
            ['id' => 'dev.horizon', 'label' => 'Horizon', 'hint' => 'Embedded queue dashboard', 'icon' => '↗', 'route' => 'dev.horizon', 'keywords' => ['queue', 'dashboard']],
            ['id' => 'dev.sync-health', 'label' => 'Sync Health', 'hint' => 'Quarantined / skipped merge ops', 'icon' => '⇄', 'route' => 'dev.sync-health', 'keywords' => ['sync', 'quarantine', 'merge', 'oplog']],
        ];
    }

    private function registerAppActionRegistry(): void
    {
        $this->app->singleton(AppActionRegistry::class, static function (Application $app): AppActionRegistryImpl {
            $router = $app->make(Router::class);

            $actions = [];

            $importsNew = self::resolveRouteUrl($router, 'imports.new');
            if ($importsNew !== null) {
                $actions[] = new AppAction(
                    id: 'action.run-import',
                    label: 'Run import',
                    hint: 'Open the import wizard',
                    icon: '⊕',
                    handlerEvent: null,
                    url: $importsNew,
                    keywords: ['upload', 'csv', 'statement'],
                );
            }

            $inboxes = self::resolveRouteUrl($router, 'inboxes.index');
            if ($inboxes !== null) {
                $actions[] = new AppAction(
                    id: 'action.scan-email',
                    label: 'Scan email now',
                    hint: 'Run the inbox sync immediately',
                    icon: '✉',
                    handlerEvent: 'email-scan.run',
                    url: null,
                    keywords: ['inbox', 'gmail', 'imap', 'sync'],
                );
            }

            $settings = self::resolveRouteUrl($router, 'settings');
            if ($settings !== null) {
                $actions[] = new AppAction(
                    id: 'action.open-profile',
                    label: 'Open profile',
                    hint: 'Settings — account and preferences',
                    icon: '⚙',
                    handlerEvent: null,
                    url: $settings,
                    keywords: ['profile', 'preferences', 'account'],
                );

                $actions[] = new AppAction(
                    id: 'action.toggle-theme',
                    label: 'Toggle theme',
                    hint: 'Switch between light and dark',
                    icon: '◐',
                    handlerEvent: 'theme.cycle',
                    url: null,
                    keywords: ['dark', 'light', 'appearance'],
                );
            }

            return new AppActionRegistryImpl($actions);
        });
    }

    private function registerRedactionServices(): void
    {
        // Holds decrypted secrets, so it must be a singleton the boot()
        // observer can bust — a rotated secret has to apply immediately.
        $this->app->singleton(OAuthScrubSet::class, static fn (Application $app): OAuthScrubSet => new OAuthScrubSet(
            $app->make(SecretShield::class),
        ));

        $this->app->singleton(RedactionExcerptCap::class, static fn (Application $app): RedactionExcerptCap => new RedactionExcerptCap(
            $app->make(OAuthScrubSet::class),
        ));

        // config/logging.php cannot inject, so the Monolog tap resolves
        // this binding instead of constructing the processor itself.
        $this->app->singleton(RedactSecretsProcessor::class, static fn (Application $app): RedactSecretsProcessor => new RedactSecretsProcessor(
            $app->make(OAuthScrubSet::class),
        ));
    }

    private function registerAuditServices(): void
    {
        $this->app->singleton(AuditWriter::class, static fn (Application $app): SpatieAuditWriter => new SpatieAuditWriter(
            $app->make(CurrentUser::class),
            $app->make(Clock::class),
            $app->make(RedactionExcerptCap::class),
            $app->make(ActivityLogger::class),
            $app->make(DatabaseManager::class),
        ));

        $this->app->singleton(FinalizeRunAudit::class, static fn (Application $app): FinalizeRunAudit => new FinalizeRunAudit(
            $app->make(AuditWriter::class),
            $app->make(RunRegistry::class),
            $app->make(Clock::class),
        ));
    }

    private static function resolveRouteUrl(Router $router, string $name): ?string
    {
        if (! $router->getRoutes()->hasNamedRoute($name)) {
            return null;
        }

        try {
            $resolved = $router->getRoutes()->getByName($name)?->uri();
        } catch (\Throwable) {
            return null;
        }

        return is_string($resolved) && $resolved !== '' ? '/'.ltrim($resolved, '/') : null;
    }

    public function boot(Router $router, LivewireManager $livewire, Dispatcher $events, ConfigRepository $config): void
    {
        $router->aliasMiddleware('ensureDeveloperMode', EnsureDeveloperMode::class);

        $this->loadModuleResources('dev');

        // Horizon is a require-dev package, so its absence from a
        // production build is a second guard beyond the dev_mode flag.
        if ($config->get('app.dev_mode') === true && class_exists(\Laravel\Horizon\HorizonServiceProvider::class)) {
            $horizonProviderClass = HorizonServiceProvider::class;
            if (class_exists($horizonProviderClass)) {
                $router->group(
                    [
                        'middleware' => ['web', 'auth', 'ensureDeveloperMode'],
                        'prefix' => '/dev',
                    ],
                    static function (Router $router): void {
                        $router->get('/horizon', HorizonFramePage::class)
                            ->middleware(HorizonFrameAncestors::class)
                            ->name('dev.horizon');
                    },
                );
            }
        }

        $livewire->component('dev.overview-page', DevOverviewPage::class);
        $livewire->component('dev.triple-gate-modal', TripleGateModal::class);
        $livewire->component('dev.artisan-runner-page', ArtisanRunnerPage::class);
        $livewire->component('dev.audit-log-page', AuditLogPage::class);
        $livewire->component('dev.log-tailer-page', LogTailerPage::class);
        $livewire->component('dev.queue-inspector-page', QueueInspectorPage::class);
        $livewire->component('dev.horizon-frame-page', HorizonFramePage::class);
        $livewire->component('dev.doctor-panel-page', DoctorPanelPage::class);
        $livewire->component('dev.system-snapshot-page', SystemSnapshotPage::class);
        $livewire->component('dev.sql-panel-page', SqlPanelPage::class);
        $livewire->component('dev.command-palette-modal', CommandPaletteModal::class);
        $livewire->component('dev.command-arg-prompt-modal', CommandArgPromptModal::class);

        // Re-resolved per tick rather than closed over, so a worker running
        // for hours keeps writing through current bindings.
        /** @var QueueManager $queueManager */
        $queueManager = $this->app->make(QueueManager::class);
        $appLocal = $this->app;
        $queueManager->looping(static function () use ($appLocal): void {
            /** @var WriteWorkerHeartbeat $heartbeat */
            $heartbeat = $appLocal->make(WriteWorkerHeartbeat::class);
            ($heartbeat)();
        });

        // Session resume does not fire Login, so ArtisanRunnerPage::mount()
        // resets the Advanced toggle a second time per session.
        $events->listen(Login::class, [ResetAdvancedToggleOnLogin::class, 'handle']);

        // The database driver and Horizon both delete successful rows from
        // `jobs`, so the log is the only seam that sees a completed job.
        $events->listen(JobProcessed::class, [LogQueueLifecycle::class, 'processed']);
        $events->listen(JobFailed::class, [LogQueueLifecycle::class, 'failed']);

        OAuthSecret::observe(BustOAuthScrubSetOnSecretChange::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneDevAuditCommand::class,
            ]);
        }
    }
}
