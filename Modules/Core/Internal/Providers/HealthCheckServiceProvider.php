<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Internal\Listeners\HealthCheckListener;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class HealthCheckServiceProvider extends ServiceProvider
{
    public function boot(Dispatcher $events): void
    {
        $events->listen(ConnectionEstablished::class, HealthCheckListener::class);
    }
}
