<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Queue\Events\JobProcessing;

// Starts every WORKER job with nobody signed in. A job that acts for a user
// binds one onto the guard, and nothing unbound them: `auth` is a singleton,
// so the worker's forgetScopedInstances() never drops it. The next job in that
// process inherited whoever ran last, and UserScope scopes off this guard.
final readonly class ClearGuardBetweenJobs
{
    public function __construct(
        private AuthManager $auth,
        private Config $config,
    ) {}

    public function handle(JobProcessing $event): void
    {
        // The sync driver runs the job INSIDE the caller's request, sharing
        // its authentication on purpose — clearing there signs the caller out
        // mid-request. Only a worker outlives the job it just ran.
        if ($this->driverFor($event->connectionName) === 'sync') {
            return;
        }

        $this->auth->forgetGuards();
    }

    private function driverFor(mixed $connectionName): ?string
    {
        if (! is_string($connectionName)) {
            return null;
        }

        $driver = $this->config->get('queue.connections.'.$connectionName.'.driver');

        return is_string($driver) ? $driver : null;
    }
}
