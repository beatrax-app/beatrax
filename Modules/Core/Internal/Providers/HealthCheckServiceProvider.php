<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Internal\Listeners\HealthCheckListener;

/**
 * Wires the `HealthCheckListener` (Modules/Core/Internal/Listeners/) as
 * the handler for Laravel's `ConnectionEstablished` event. The listener
 * itself owns the PRAGMA drift detection + system_alerts row write +
 * 1-hour recency suppression logic; this provider only owns the
 * dispatcher binding.
 *
 * The provider is registered through `CoreServiceProvider::register()`
 * alongside the existing `SqliteOptimizationsProvider`. The `boot()`
 * signature accepts `Dispatcher` via Laravel's method-arg resolution;
 * the listener resolves through the container so its constructor
 * dependencies (BootProbeState / Clock / LoggerInterface /
 * DatabaseManager) get DI'd at construction time.
 */
final class HealthCheckServiceProvider extends ServiceProvider
{
    public function boot(Dispatcher $events): void
    {
        $events->listen(ConnectionEstablished::class, HealthCheckListener::class);
    }
}
