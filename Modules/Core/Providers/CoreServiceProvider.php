<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Internal\Console\BackupDatabaseCommand;
use Modules\Core\Internal\Console\DoctorCommand;
use Modules\Core\Internal\Console\FailedJobsCommand;
use Modules\Core\Internal\Console\InstallCommand;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Console\RestoreDatabaseCommand;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Internal\Http\Livewire\Dashboard;
use Modules\Core\Internal\Http\Livewire\EncryptedBackupDownload;
use Modules\Core\Internal\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Internal\Http\Livewire\HelpDataLocations;
use Modules\Core\Internal\Http\Livewire\NetWorthCard;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Internal\Http\Livewire\SpendingTrendCard;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Internal\Providers\HealthCheckServiceProvider;
use Modules\Core\Internal\Providers\SqliteOptimizationsProvider;
use Modules\Core\Models\User as CoreUser;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\CurrentUserService;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Core\Public\Services\PassthroughSecretShield;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\LoadsModuleResources;

/**
 * @link ../../../.docs/features/core/architecture.md
 */
final class CoreServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->register(SqliteOptimizationsProvider::class);
        $this->app->singleton(BootProbeState::class);
        $this->app->register(HealthCheckServiceProvider::class);
        $this->app->singleton(Clock::class, SystemClock::class);

        // Bound to the interface so callers that translate a libsodium
        // failure into their own type can be driven from a test.
        $this->app->singleton(FileEncryptor::class, BackupEncryptor::class);
        // Named constructor, so the class cannot be built with neither a
        // container nor a session.
        $this->app->singleton(SessionFactory::class, fn () => SessionFactory::fromContainer($this->app));

        $this->app->bind(CurrentUser::class, CurrentUserService::class);

        // At-rest keychain shielding for persisted secrets (biometric wrap
        // blob, OAuth tokens). Defaults to the pass-through shield (no
        // behaviour change); the Desktop provider overrides this on its
        // bundle to add the Electron safeStorage layer.
        $this->app->singleton(SecretShield::class, PassthroughSecretShield::class);
        $this->app->singleton(SystemAlertQuery::class);
        $this->app->singleton(AcknowledgeSystemAlert::class);

        // Single source of truth for every filesystem path the app reads or
        // writes. Dependency-free and stateless, so a plain singleton (no
        // closure) is correct; the backup command, restore command, and
        // freshness probe all inject this service to resolve the backups dir.
        $this->app->singleton(UserDataPathService::class);
        $this->app->singleton(NavCountsService::class);
        $this->app->singleton(RestoreEncryptedBackup::class);

        // The backup-first atomic encryption migration depends on
        // Modules\Sync\Internal\Crypto singletons (GdkKeyringService /
        // OpLogFieldCrypto) already registered by SyncServiceProvider::
        // register() — binding order across module providers doesn't matter.
        $this->app->singleton(PreMigrationSnapshot::class);
        $this->app->singleton(EncryptionMigrationService::class);

        if (! class_exists(User::class, false)) {
            class_alias(CoreUser::class, User::class);
        }
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('core');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

        $livewire->component('core.dashboard', Dashboard::class);
        $livewire->component('core.settings-page', SettingsPage::class);
        $livewire->component('core.encrypted-backup-download', EncryptedBackupDownload::class);
        $livewire->component('core.encrypted-backup-restore', EncryptedBackupRestore::class);
        $livewire->component('core.spending-trend-card', SpendingTrendCard::class);
        $livewire->component('core.net-worth-card', NetWorthCard::class);
        $livewire->component('core.system-alerts-banner', SystemAlertsBanner::class);
        $livewire->component('core.app-sidebar', AppSidebar::class);
        $livewire->component('core.help-data-locations', HelpDataLocations::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                BackupDatabaseCommand::class,
                RestoreDatabaseCommand::class,
                FailedJobsCommand::class,
            ]);
        }
    }
}
