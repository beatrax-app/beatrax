<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Internal\Console\BackupDatabaseCommand;
use Modules\Core\Internal\Console\DoctorCommand;
use Modules\Core\Internal\Console\FailedJobsCommand;
use Modules\Core\Internal\Console\InstallCommand;
use Modules\Core\Internal\Console\Probes\BackupFreshnessProbe;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Console\RestoreDatabaseCommand;
use Modules\Core\Internal\Http\Livewire\Dashboard;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Internal\Http\Livewire\TopNav;
use Modules\Core\Internal\Providers\FortifyServiceProvider;
use Modules\Core\Internal\Providers\HealthCheckServiceProvider;
use Modules\Core\Internal\Providers\SqliteOptimizationsProvider;
use Modules\Core\Models\User as CoreUser;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\CurrentUserService;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\SystemClock;

/**
 * Wires the Core module: registers the SQLite pragma listener, binds the
 * public Clock + CurrentUser contracts, loads migrations, routes, and
 * views, and registers the install + doctor artisan commands. It also
 * aliases `App\Models\User` to the canonical `Modules\Core\Models\User`
 * so framework consumers expecting the default Laravel namespace
 * (`auth.providers.users.model` in `config/auth.php`, the
 * Tests\TestCase typed reference, and Laravel's notification routing)
 * resolve the same class as the module-namespaced model.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(SqliteOptimizationsProvider::class);
        $this->app->singleton(BootProbeState::class);
        $this->app->register(HealthCheckServiceProvider::class);
        $this->app->register(FortifyServiceProvider::class);
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(CurrentUser::class, CurrentUserService::class);
        $this->app->singleton(SystemAlertQuery::class);
        $this->app->singleton(AcknowledgeSystemAlert::class);

        // Resolve the SQLite backups directory once and expose it under a
        // string-keyed binding so tests can swap in a sys_get_temp_dir()
        // path without touching the developer's real storage/app/backups.
        // BackupDatabaseCommand picks it up through a contextual binding
        // below. The closure receives the container as its sole argument
        // so the `Application::basePath()` call goes through DI instead
        // of the global `base_path()` helper (CLAUDE.md DI-only rule).
        $this->app->singleton(
            'core.backups_directory',
            static fn (Application $app): string => $app->basePath('storage/app/backups'),
        );

        $this->app->when(BackupDatabaseCommand::class)
            ->needs('$backupsPath')
            ->give(fn () => $this->app->make('core.backups_directory'));

        // RestoreDatabaseCommand consumes the same contextual `$backupsPath`
        // string as BackupDatabaseCommand so both console commands share
        // one DI shape — preferred over reaching into the container via
        // `getLaravel()->make()` at runtime, which is a Service Locator
        // anti-pattern.
        $this->app->when(RestoreDatabaseCommand::class)
            ->needs('$backupsPath')
            ->give(fn () => $this->app->make('core.backups_directory'));

        // BackupFreshnessProbe reads the same `core.backups_directory`
        // binding as BackupDatabaseCommand so the doctor probe inspects
        // the directory the backup command writes to. Tests override
        // `core.backups_directory` once and both surfaces follow.
        $this->app->when(BackupFreshnessProbe::class)
            ->needs('$backupsPath')
            ->give(fn () => $this->app->make('core.backups_directory'));

        if (! class_exists(User::class, false)) {
            class_alias(CoreUser::class, User::class);
        }
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'core');

        $livewire->component('core.dashboard', Dashboard::class);
        $livewire->component('core.settings-page', SettingsPage::class);
        $livewire->component('core.system-alerts-banner', SystemAlertsBanner::class);
        $livewire->component('core.top-nav', TopNav::class);

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
