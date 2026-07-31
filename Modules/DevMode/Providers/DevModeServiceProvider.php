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
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Audit\RedactionExcerptCap;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\CommandRegistry;
use Modules\DevMode\Internal\Console\PruneDevAuditCommand;
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
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * @link ../../../.docs/features/dev-mode/architecture.md
 */
final class DevModeServiceProvider extends ServiceProvider
{
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

    // QueueInspectorPage resolves QueueRowLoader on demand each render
    // (never as a persisted Livewire property); binding it here makes the
    // collaborator's DatabaseManager dependency explicit at the container.
    private function registerQueueServices(): void
    {
        $this->app->singleton(QueueRowLoader::class, static fn (Application $app): QueueRowLoader => new QueueRowLoader(
            $app->make(DatabaseManager::class),
        ));
    }

    // SAFE + DESTRUCTIVE tier roster. NEVER-EXPOSED commands (migrate,
    // migrate:rollback, db:seed) are deliberately absent — the spawner
    // whitelists against CommandRegistry::find() so any attempt to spawn
    // one throws InvalidArgumentException first.
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
                name: 'beatrax:failed-jobs',
                label: 'Prune failed jobs',
                tier: 'safe',
                argsSchema: [
                    new ArgSpec(
                        name: 'action',
                        label: 'Action',
                        type: 'select',
                        rules: ['required', 'in:prune'],
                        options: ['prune'],
                    ),
                ],
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
                    // Laravel's ConfigShowCommand signature is
                    // `config:show {config}` — the positional argument
                    // is REQUIRED; a no-arg invocation aborts Symfony
                    // Console with "Not enough arguments (missing: config)".
                    new ArgSpec(
                        name: 'config',
                        label: 'Config key',
                        type: 'text',
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

        // The dev-shell layout gates `nav-disabled` on Router::has(...)
        // at render time, so runtime truth wins over this constant;
        // the constant's `enabled` field only documents intended state.
        $this->app->singleton(DevSidebarItems::class, static fn (): DevSidebarItems => new DevSidebarItems);
    }

    // The full roster of authenticated app views (main-app surfaces + Dev
    // Console sub-routes) is materialised lazily through the UrlGenerator,
    // after every module has registered its routes. `dev.`-prefixed ids
    // are filtered for non-developers at JSON-emit time.
    private function registerNavigationRegistry(): void
    {
        $this->app->singleton(NavigationRegistry::class, static function (Application $app): NavigationRegistryImpl {
            $router = $app->make(Router::class);

            return new NavigationRegistryImpl(array_merge(
                self::navEntries($router, self::mainNavRows()),
                self::navEntries($router, self::devNavRows()),
            ));
        });
    }

    // Resolves each row's route name to a relative URL, dropping rows
    // whose route is absent in the current bundle (Horizon iframe,
    // optional sub-features) so the registry stays well-defined across
    // every load order.
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

    // Main-app nav. Each entry's `id` mirrors the route name so the
    // palette's Recent cache dedupes on the canonical identifier.
    /**
     * @return list<array{id: string, label: string, hint: string, icon: string, route: string, keywords: list<string>}>
     */
    private static function mainNavRows(): array
    {
        return [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'hint' => 'Recent activity overview', 'icon' => '◆', 'route' => 'dashboard', 'keywords' => ['home', 'main', 'this month']],
            ['id' => 'transactions.index', 'label' => 'Transactions', 'hint' => 'Browse transactions', 'icon' => '≡', 'route' => 'transactions.index', 'keywords' => ['txn', 'ledger']],
            ['id' => 'forecast.index', 'label' => 'Forecasts', 'hint' => 'What-if scenarios', 'icon' => '↗', 'route' => 'forecast.index', 'keywords' => ['scenario', 'predict']],
            ['id' => 'calendar.index', 'label' => 'Calendar', 'hint' => 'Upcoming fixed payments and projected daily balance', 'icon' => '▦', 'route' => 'calendar.index', 'keywords' => ['bills', 'payments', 'balance', 'cash flow']],
            ['id' => 'recurring.index', 'label' => 'Recurring', 'hint' => 'Subscriptions and fixed payments', 'icon' => '↻', 'route' => 'recurring.index', 'keywords' => ['subscriptions', 'fixed']],
            ['id' => 'chains.review', 'label' => 'Chains', 'hint' => 'Cross-account funding chains', 'icon' => '⇉', 'route' => 'chains.review', 'keywords' => ['routing', 'funding']],
            ['id' => 'drift.index', 'label' => 'Drift Alerts', 'hint' => 'Subscription-price drift watch', 'icon' => '⚠', 'route' => 'drift.index', 'keywords' => ['alerts', 'price']],
            ['id' => 'imports.new', 'label' => 'Imports', 'hint' => 'Upload statements', 'icon' => '⊕', 'route' => 'imports.new', 'keywords' => ['upload', 'csv', 'mt940', 'camt']],
            ['id' => 'inboxes.index', 'label' => 'Email', 'hint' => 'Connected inboxes', 'icon' => '✉', 'route' => 'inboxes.index', 'keywords' => ['inbox', 'gmail', 'imap']],
            ['id' => 'uncategorized', 'label' => 'Categorization', 'hint' => 'Review uncategorized transactions', 'icon' => '⌕', 'route' => 'uncategorized', 'keywords' => ['rules', 'tag']],
            ['id' => 'settings', 'label' => 'Settings', 'hint' => 'App preferences', 'icon' => '⚙', 'route' => 'settings', 'keywords' => ['prefs', 'config', 'profile']],
            ['id' => 'tax.index', 'label' => 'Tax', 'hint' => 'Deductible records and per-year export', 'icon' => '⊞', 'route' => 'tax.index', 'keywords' => ['deduction', 'aangifte', 'export', 'records']],
        ];
    }

    // Dev Console sub-routes. Filtered at JSON-emit time by
    // CommandPaletteModal::buildRegistry() so non-developers never see
    // these labels — defense-in-depth on top of the EnsureDeveloperMode
    // middleware on the routes themselves.
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

    // URL-shaped actions resolve route names through the UrlGenerator at
    // resolution time so missing routes drop out cleanly. The handlerEvent
    // rows ("Scan email now", "Toggle theme") dispatch as Livewire browser
    // events instead of a URL.
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
        // Lazily loads the decrypted oauth_secrets.client_secret + every
        // string in tokens_blob on first all()/compiledPattern() call;
        // the Eloquent observer registered in boot() busts this cache
        // on every save/delete so a rotated secret applies immediately.
        $this->app->singleton(OAuthScrubSet::class, static fn (Application $app): OAuthScrubSet => new OAuthScrubSet(
            $app->make(SecretShield::class),
        ));

        // RedactionExcerptCap with the OAuthScrubSet singleton wired
        // in so the audit-row excerpt scrubs every oauth_secret
        // literal before the Bearer + JWT pattern. SpatieAuditWriter
        // resolves the cap via this container singleton.
        $this->app->singleton(RedactionExcerptCap::class, static fn (Application $app): RedactionExcerptCap => new RedactionExcerptCap(
            $app->make(OAuthScrubSet::class),
        ));

        // PushRedactProcessor (the Monolog tap class) resolves this
        // singleton on every channel boot via the container, keeping its
        // DI chain invisible to config/logging.php. Direct instantiation
        // still works because the OAuthScrubSet constructor arg is nullable.
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

    // Resolves a route name to a relative URL, or null when the route is
    // absent in the current bundle (Horizon iframe, optional sub-features)
    // or its lookup throws, so callers stay well-defined across load order.
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

        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dev');
        }

        // Registered only when BOTH the dev_mode env flag is true AND the
        // Horizon package (require-dev) is present; the dev-shell sidebar
        // reads Route::has('dev.horizon') to gate the nav item. See
        // .docs/features/dev-mode/architecture.md for the arch invariants.
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
        // Global command-palette modal. Mounted in both base layouts
        // so ⌘K / Ctrl+K fires `palette:open` from anywhere in the
        // app.
        $livewire->component('dev.command-palette-modal', CommandPaletteModal::class);
        $livewire->component('dev.command-arg-prompt-modal', CommandArgPromptModal::class);

        // The QueueManager::looping(closure) form is used because the
        // event-listener form (Looping::class) does NOT reliably fire
        // under Laravel's queue:work. The closure resolves
        // WriteWorkerHeartbeat from the container on every tick.
        /** @var QueueManager $queueManager */
        $queueManager = $this->app->make(QueueManager::class);
        $appLocal = $this->app;
        $queueManager->looping(static function () use ($appLocal): void {
            /** @var WriteWorkerHeartbeat $heartbeat */
            $heartbeat = $appLocal->make(WriteWorkerHeartbeat::class);
            ($heartbeat)();
        });

        // The Advanced toggle resets to OFF on every successful Login;
        // ArtisanRunnerPage::mount() resets it a second time on
        // first-load-per-session as a belt-and-braces for the
        // session-resume path that does NOT fire Login.
        $events->listen(Login::class, [ResetAdvancedToggleOnLogin::class, 'handle']);

        // Both the database queue driver and Horizon delete successful
        // rows from `jobs` on completion, so the queue inspector cannot
        // surface them — the Laravel log is the visibility seam instead.
        $events->listen(JobProcessed::class, [LogQueueLifecycle::class, 'processed']);
        $events->listen(JobFailed::class, [LogQueueLifecycle::class, 'failed']);

        // Attach the Eloquent observer so the scrub set busts on every
        // OAuthSecret save/delete; the next log line or audit row's
        // compiledPattern() lazy-rebuilds from the live table.
        OAuthSecret::observe(BustOAuthScrubSetOnSecretChange::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneDevAuditCommand::class,
            ]);
        }
    }
}
