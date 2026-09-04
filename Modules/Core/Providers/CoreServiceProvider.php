<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Modules\Core\Internal\Console\BackupDatabaseCommand;
use Modules\Core\Internal\Console\DoctorCommand;
use Modules\Core\Internal\Console\FailedJobsCommand;
use Modules\Core\Internal\Console\InstallCommand;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Console\RestoreDatabaseCommand;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Internal\Encryption\ProductionKdfCost;
use Modules\Core\Internal\Http\Livewire\HelpDataLocations;
use Modules\Core\Internal\Http\Livewire\SafeEnumSynth;
use Modules\Core\Internal\Listeners\ClearGuardBetweenJobs;
use Modules\Core\Internal\Listeners\ForgetNavCountsOnWrite;
use Modules\Core\Internal\Providers\HealthCheckServiceProvider;
use Modules\Core\Internal\Providers\SqliteOptimizationsProvider;
use Modules\Core\Internal\Support\MigrationWindow;
use Modules\Core\Models\User as CoreUser;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Contracts\KdfCost;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Http\Livewire\AutoImportSettingsSection;
use Modules\Core\Public\Http\Livewire\EncryptedBackupDownload;
use Modules\Core\Public\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\CurrentUserService;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Core\Public\Services\PassthroughSecretShield;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\AppChromeResolver;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Core\Public\Support\RegistersScheduledCommands;

final class CoreServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;
    use RegistersScheduledCommands;

    public function register(): void
    {
        $this->app->register(SqliteOptimizationsProvider::class);
        $this->app->singleton(BootProbeState::class);
        $this->app->register(HealthCheckServiceProvider::class);
        $this->app->singleton(Clock::class, SystemClock::class);

        // Unconditional, and the only binding of this contract anywhere: the
        // suite replaces the instance from Tests\TestCase, so no environment
        // check exists here that a deployed install could ever satisfy.
        $this->app->singleton(KdfCost::class, ProductionKdfCost::class);

        // Bound to the interface so callers that translate a libsodium
        // failure into their own type can be driven from a test.
        $this->app->singleton(FileEncryptor::class, BackupEncryptor::class);
        // Named constructor, so the class cannot be built with neither a
        // container nor a session.
        $this->app->singleton(SessionFactory::class, fn (): SessionFactory => SessionFactory::fromContainer($this->app));

        $this->app->bind(CurrentUser::class, CurrentUserService::class);

        // At-rest keychain shielding for persisted secrets (biometric wrap
        // blob, OAuth tokens). Defaults to the pass-through shield (no
        // behaviour change); the Desktop provider overrides this on its
        // bundle to add the Electron safeStorage layer.
        $this->app->singleton(SecretShield::class, PassthroughSecretShield::class);
        $this->app->bind(PublisherManifestFetcher::class, HttpPublisherManifestFetcher::class);
        $this->app->singleton(SystemAlertQuery::class);
        $this->app->singleton(UserCountry::class);
        $this->app->singleton(AppChromeResolver::class);

        // Single source of truth for every filesystem path the app reads or
        // writes. Dependency-free and stateless, so a plain singleton (no
        // closure) is correct; the backup command, restore command, and
        // freshness probe all inject this service to resolve the backups dir.
        $this->app->singleton(UserDataPathService::class);
        $this->app->singleton(NavCountsService::class);
        $this->app->singleton(MigrationWindow::class);
        $this->app->singleton(RestoreEncryptedBackup::class);

        // The backup-first atomic encryption migration depends on
        // Modules\Sync\Internal\Crypto singletons (GdkKeyringService /
        // OpLogFieldCrypto) already registered by SyncServiceProvider::
        // register() — binding order across module providers doesn't matter.
        $this->app->singleton(PreMigrationSnapshot::class);

        if (! class_exists(User::class, false)) {
            class_alias(CoreUser::class, User::class);
        }
    }

    public function boot(LivewireManager $livewire): void
    {
        // The desktop bundle launches PHP with -d max_execution_time=120:
        // meaningless for console work, fatal for long-running commands. The
        // queue worker and both sync daemons died every two minutes, and one
        // killed mid-transaction left SQLite locked.
        if ($this->app->runningInConsole()) {
            set_time_limit(0);
        }

        // Whoever a queued job binds must not outlive it. Registered here
        // rather than left to each job's own discipline: a job that forgets is
        // indistinguishable from one that succeeds, and the damage lands on
        // the NEXT job, scoped to the wrong user.
        $app = $this->app;
        $dispatcher = $app->make(Dispatcher::class);
        $dispatcher->listen(JobProcessing::class, ClearGuardBetweenJobs::class);

        // Same reasoning one layer down: the sidebar badge invalidation is
        // taken from the statement, because the eight modules that write those
        // tables are eight places that could forget and seven of them had.
        $dispatcher->listen(QueryExecuted::class, ForgetNavCountsOnWrite::class);

        // Mobile has no `sqlite3` binary, so its first launch cannot load the
        // squashed schema and runs every migration from the first one. The
        // window tells the listener above which statements are that run's.
        $dispatcher->listen(MigrationsStarted::class, static function () use ($app): void {
            $app->make(MigrationWindow::class)->open();
        });
        $dispatcher->listen(MigrationsEnded::class, static function () use ($app): void {
            $app->make(MigrationWindow::class)->close();
        });

        $this->loadModuleResources('core');

        $this->registerScheduledCommands([BackupDatabaseCommand::class]);

        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

        // Ahead of every component registration below, so no component can be
        // resolved through the framework's own enum synth first.
        $livewire->propertySynthesizer(SafeEnumSynth::class);

        $livewire->component('core.auto-import-settings-section', AutoImportSettingsSection::class);
        $livewire->component('core.encrypted-backup-download', EncryptedBackupDownload::class);
        $livewire->component('core.encrypted-backup-restore', EncryptedBackupRestore::class);
        $livewire->component('core.system-alerts-banner', SystemAlertsBanner::class);
        $livewire->component('core.help-data-locations', HelpDataLocations::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                RestoreDatabaseCommand::class,
                FailedJobsCommand::class,
            ]);
        }
    }
}
