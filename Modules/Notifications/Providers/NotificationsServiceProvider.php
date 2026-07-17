<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;

/**
 * Wires the Notifications module.
 *
 * Per D-26 the notification store must boot on web, artisan AND mobile —
 * unlike `Modules\Desktop`, this provider is NEVER `class_exists`-guarded.
 *
 * register() binds the stateless in-tree services as singletons. In this
 * wave only the store's sole mutator (`NotificationStateMachine`) and the
 * single deterministic key-derivation site (`DeterministicKeyDeriver`)
 * exist; later plans add the Public read/write surface, the trigger
 * listeners, the queued jobs, and the Livewire surface, and register
 * their own singletons / components here as they arrive.
 *
 * boot() conditionally loads the module's migrations, routes, and views
 * via `is_dir`/`is_file` guards — later plans add Livewire component
 * registrations and `class_exists()`-guarded listener wiring; boot() is
 * kept structured so those are additive.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationStateMachine::class);
        $this->app->singleton(DeterministicKeyDeriver::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'notifications');
        }
    }
}
