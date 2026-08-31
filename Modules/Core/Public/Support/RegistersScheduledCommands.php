<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;

// Registers the artisan commands `routes/console.php` schedules, deliberately
// outside any runningInConsole() guard: the mobile background runner boots
// providers with APP_RUNNING_IN_CONSOLE unset on its hot path, and a scheduled
// command it cannot resolve is a task that silently never runs.
/**
 * @phpstan-require-extends ServiceProvider
 *
 * @link ../../../../.docs/features/mobile/architecture.md#the-phone-runs-an-artisan-name-on-an-interval-and-nothing-else
 */
trait RegistersScheduledCommands
{
    /** @param  list<class-string<Command>>  $commands */
    protected function registerScheduledCommands(array $commands): void
    {
        $this->commands($commands);
    }
}
