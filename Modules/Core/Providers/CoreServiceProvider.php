<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Internal\Console\DoctorCommand;
use Modules\Core\Internal\Console\InstallCommand;
use Modules\Core\Internal\Http\Livewire\LoginForm;
use Modules\Core\Internal\Providers\FortifyServiceProvider;
use Modules\Core\Internal\Providers\SqliteOptimizationsProvider;
use Modules\Core\Models\User as CoreUser;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\CurrentUserService;
use Modules\Core\Public\Services\SystemClock;

/**
 * Wires the Core module: registers the SQLite pragma listener, binds the
 * public Clock + CurrentUser contracts, loads migrations, routes, and views,
 * registers the install + doctor artisan commands, and aliases
 * `App\Models\User` to the canonical `Modules\Core\Models\User` so legacy
 * Laravel idioms keep working alongside the module-namespaced model.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(SqliteOptimizationsProvider::class);
        $this->app->register(FortifyServiceProvider::class);
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(CurrentUser::class, CurrentUserService::class);

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

        $livewire->component('core.login-form', LoginForm::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
            ]);
        }
    }
}
