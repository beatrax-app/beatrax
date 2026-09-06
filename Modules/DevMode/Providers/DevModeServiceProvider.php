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
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\Lang;
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

    // migrate, migrate:fresh, migrate:rollback, db:wipe and db:seed are
    // deliberately absent: the spawner whitelists against find(), so spawning
    // one throws. The absence is a test, not a review habit — review carried
    // migrate:fresh here for the whole life of the destructive tier.
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
            // No destination: db:backup names its own file inside the backups
            // directory, because retention pruning, duplicate detection and the
            // sidecar all key off that directory. A path offered here reached
            // artisan as a second positional and came back "Too many arguments".
            new CommandSpec(
                name: 'db:backup',
                labelKey: 'dev::runner.command.db_backup.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.db_backup.description',
            ),
            new CommandSpec(
                name: 'beatrax:doctor',
                labelKey: 'dev::runner.command.doctor.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.doctor.description',
            ),
            new CommandSpec(
                name: 'beatrax:failed-jobs',
                labelKey: 'dev::runner.command.failed_jobs.label',
                tier: CommandTier::Safe,
                argsSchema: [
                    new ArgSpec(
                        name: 'action',
                        labelKey: 'dev::runner.arg.action.label',
                        type: ArgType::Select,
                        rules: ['required', 'in:prune'],
                        options: ['prune'],
                    ),
                ],
                descriptionKey: 'dev::runner.command.failed_jobs.description',
            ),
            new CommandSpec(
                name: 'cache:clear',
                labelKey: 'dev::runner.command.cache_clear.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.cache_clear.description',
            ),
            new CommandSpec(
                name: 'route:list',
                labelKey: 'dev::runner.command.route_list.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.route_list.description',
            ),
            new CommandSpec(
                name: 'config:show',
                labelKey: 'dev::runner.command.config_show.label',
                tier: CommandTier::Safe,
                argsSchema: [
                    // config:show's positional argument is required upstream;
                    // a no-arg invocation aborts Symfony Console.
                    new ArgSpec(
                        name: 'config',
                        labelKey: 'dev::runner.arg.config.label',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:255'],
                        placeholderKey: 'dev::runner.arg.config.placeholder',
                        helpTextKey: 'dev::runner.arg.config.help',
                    ),
                ],
                descriptionKey: 'dev::runner.command.config_show.description',
            ),
            new CommandSpec(
                name: 'view:clear',
                labelKey: 'dev::runner.command.view_clear.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.view_clear.description',
            ),
            new CommandSpec(
                name: 'queue:retry',
                labelKey: 'dev::runner.command.queue_retry.label',
                tier: CommandTier::Safe,
                argsSchema: [
                    new ArgSpec(
                        name: 'id',
                        labelKey: 'dev::runner.arg.id.label',
                        type: ArgType::Text,
                        rules: ['nullable', 'string', 'max:64'],
                        placeholderKey: 'dev::runner.arg.id.placeholder',
                        helpTextKey: 'dev::runner.arg.id.help',
                    ),
                    new ArgSpec(
                        name: '--queue',
                        labelKey: 'dev::runner.arg.queue.label',
                        type: ArgType::Text,
                        rules: ['nullable', 'string', 'max:255'],
                        placeholderKey: 'dev::runner.arg.queue.placeholder',
                        helpTextKey: 'dev::runner.arg.queue.help',
                    ),
                ],
                descriptionKey: 'dev::runner.command.queue_retry.description',
            ),
            new CommandSpec(
                name: 'beatrax:rederive-fingerprints',
                labelKey: 'dev::runner.command.rederive_fingerprints.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.rederive_fingerprints.description',
            ),
            // Safe, and without the --reset the command also offers: adding is
            // what somebody looking at an empty install wants, and tearing down
            // what is already there is a decision the console should not carry
            // a one-click for. The teardown stays on the command line.
            new CommandSpec(
                name: 'demo:seed',
                labelKey: 'dev::runner.command.demo_seed.label',
                tier: CommandTier::Safe,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.demo_seed.description',
            ),
        ];
    }

    /**
     * @return list<CommandSpec>
     */
    private static function destructiveCommands(): array
    {
        return [
            // The two flags are fixed rather than prompted because neither is a
            // decision this operator can make: the console cannot run `down`
            // beforehand, and the y/N the flag skips is the consent the triple
            // gate already took by typed application name.
            new CommandSpec(
                name: 'db:restore',
                labelKey: 'dev::runner.command.db_restore.label',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'path',
                        labelKey: 'dev::runner.arg.path.label',
                        type: ArgType::FilePath,
                        rules: ['required', 'string', 'max:1024'],
                        placeholderKey: 'dev::runner.arg.path.placeholder',
                        helpTextKey: 'dev::runner.arg.path.help',
                    ),
                ],
                descriptionKey: 'dev::runner.command.db_restore.description',
                fixedFlags: ['--confirm', '--force-maintenance'],
            ),
            new CommandSpec(
                name: 'beatrax:regenerate-recovery-codes',
                labelKey: 'dev::runner.command.regenerate_recovery_codes.label',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        labelKey: 'dev::runner.arg.username.label',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:64'],
                        placeholderKey: 'dev::runner.arg.username.placeholder',
                    ),
                ],
                descriptionKey: 'dev::runner.command.regenerate_recovery_codes.description',
            ),
            new CommandSpec(
                name: 'beatrax:grant-dev',
                labelKey: 'dev::runner.command.grant_dev.label',
                tier: CommandTier::Destructive,
                argsSchema: [
                    new ArgSpec(
                        name: 'username',
                        labelKey: 'dev::runner.arg.username.label',
                        type: ArgType::Text,
                        rules: ['required', 'string', 'max:64'],
                        placeholderKey: 'dev::runner.arg.username.placeholder',
                    ),
                ],
                descriptionKey: 'dev::runner.command.grant_dev.description',
            ),
            // Destructive for what a RE-RUN does, not for the first install:
            // `migrate --force` applies pending migrations to a live ledger, and
            // re-dispatching UserInstalled re-parents a default category the
            // reader moved and re-creates a default rule they deleted.
            new CommandSpec(
                name: 'beatrax:install',
                labelKey: 'dev::runner.command.install.label',
                tier: CommandTier::Destructive,
                argsSchema: [],
                descriptionKey: 'dev::runner.command.install.description',
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
        // Read here rather than carried as a key: NavigationRegistry is
        // bound rather than shared, so every resolution rebuilds these rows
        // in the reader's language, which is how appNavEntries() already
        // gets its labels.

        // Doctor's gear ends in an invisible U+FE0F, and so do the palette
        // action icons further down. Without it the two phone engines disagree
        // about whether the character is a picture or a glyph.
        return [
            ['id' => 'dev.overview', 'label' => Lang::get('dev::palette.nav.overview.label'), 'hint' => Lang::get('dev::palette.nav.overview.hint'), 'icon' => '›_', 'route' => 'dev.overview', 'keywords' => ['dev', 'console']],
            ['id' => 'dev.artisan', 'label' => Lang::get('dev::palette.nav.artisan.label'), 'hint' => Lang::get('dev::palette.nav.artisan.hint'), 'icon' => '›_', 'route' => 'dev.artisan', 'keywords' => ['command', 'cli']],
            ['id' => 'dev.audit', 'label' => Lang::get('dev::palette.nav.audit.label'), 'hint' => Lang::get('dev::palette.nav.audit.hint'), 'icon' => '⌗', 'route' => 'dev.audit', 'keywords' => ['history', 'activity']],
            ['id' => 'dev.logs', 'label' => Lang::get('dev::palette.nav.logs.label'), 'hint' => Lang::get('dev::palette.nav.logs.hint'), 'icon' => '≡', 'route' => 'dev.logs', 'keywords' => ['tail', 'errors']],
            ['id' => 'dev.queue', 'label' => Lang::get('dev::palette.nav.queue.label'), 'hint' => Lang::get('dev::palette.nav.queue.hint'), 'icon' => '↻', 'route' => 'dev.queue', 'keywords' => ['jobs', 'failed', 'batches']],
            ['id' => 'dev.doctor', 'label' => Lang::get('dev::palette.nav.doctor.label'), 'hint' => Lang::get('dev::palette.nav.doctor.hint'), 'icon' => '⚙️', 'route' => 'dev.doctor', 'keywords' => ['probes', 'diagnose']],
            ['id' => 'dev.sql', 'label' => Lang::get('dev::palette.nav.sql.label'), 'hint' => Lang::get('dev::palette.nav.sql.hint'), 'icon' => '⌕', 'route' => 'dev.sql', 'keywords' => ['query', 'schema']],
            ['id' => 'dev.system', 'label' => Lang::get('dev::palette.nav.system.label'), 'hint' => Lang::get('dev::palette.nav.system.hint'), 'icon' => '◇', 'route' => 'dev.system', 'keywords' => ['env', 'config']],
            ['id' => 'dev.horizon', 'label' => Lang::get('dev::palette.nav.horizon.label'), 'hint' => Lang::get('dev::palette.nav.horizon.hint'), 'icon' => '↗', 'route' => 'dev.horizon', 'keywords' => ['queue', 'dashboard']],
            ['id' => 'dev.sync-health', 'label' => Lang::get('dev::palette.nav.sync_health.label'), 'hint' => Lang::get('dev::palette.nav.sync_health.hint'), 'icon' => '⇄', 'route' => 'dev.sync-health', 'keywords' => ['sync', 'quarantine', 'merge', 'oplog']],
        ];
    }

    private function registerAppActionRegistry(): void
    {
        $this->app->singleton(AppActionRegistry::class, static function (Application $app): AppActionRegistryImpl {
            $router = $app->make(Router::class);

            // Every row navigates, and the DTO now admits nothing else. Two of
            // them named an event instead, and nothing in the tree listened for
            // either: the palette closed, the pick was filed under Recent, and
            // the mailbox went unread while the theme went unwritten.
            $actions = [];

            $importsNew = self::resolveRouteUrl($router, Destination::Imports->routeName());
            if ($importsNew !== null) {
                $actions[] = new AppAction(
                    id: 'action.run-import',
                    labelKey: 'dev::palette.action.run_import.label',
                    hintKey: 'dev::palette.action.run_import.hint',
                    icon: '⊕',
                    url: $importsNew,
                    keywords: ['upload', 'csv', 'statement'],
                );
            }

            $inboxes = self::resolveRouteUrl($router, Destination::Email->routeName());
            if ($inboxes !== null) {
                $actions[] = new AppAction(
                    id: 'action.scan-email',
                    labelKey: 'dev::palette.action.scan_email.label',
                    hintKey: 'dev::palette.action.scan_email.hint',
                    icon: '✉️',
                    url: $inboxes,
                    keywords: ['inbox', 'gmail', 'sync', 'scan'],
                );
            }

            $settings = self::resolveRouteUrl($router, Destination::Settings->routeName());
            if ($settings !== null) {
                $actions[] = new AppAction(
                    id: 'action.open-profile',
                    labelKey: 'dev::palette.action.open_profile.label',
                    hintKey: 'dev::palette.action.open_profile.hint',
                    icon: '⚙️',
                    url: $settings,
                    keywords: ['profile', 'preferences', 'account'],
                );

                $actions[] = new AppAction(
                    id: 'action.toggle-theme',
                    labelKey: 'dev::palette.action.toggle_theme.label',
                    hintKey: 'dev::palette.action.toggle_theme.hint',
                    icon: '◐',
                    url: $settings,
                    keywords: ['dark', 'light', 'appearance', 'theme'],
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
            $app->make(SystemAlertWriter::class),
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

        // The /dev routes gate the address bar; Livewire's update endpoint runs
        // outside them, so without this a snapshot minted while the flag was on
        // kept driving the SQL panel, the runner and the queue inspector after
        // it came off -- schema and all, on a URL that answered 404.
        $livewire->addPersistentMiddleware(EnsureDeveloperMode::class);

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
