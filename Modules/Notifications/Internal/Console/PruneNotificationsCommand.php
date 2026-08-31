<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Jobs\PruneNotificationsJob;

// The job's predicate keys solely on the always-plaintext created_at column,
// never title/body/params/trigger_type, so the sweep stays bounded even on a
// locked or headless device with no data key in reach.
final class PruneNotificationsCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:prune';

    /** @var string */
    protected $description = 'Apply the notification-inbox retention sweep for every user.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->lazyById(100)->each(function (User $user): void {
            $this->bus->dispatch(new PruneNotificationsJob($user->id));
        });

        return self::SUCCESS;
    }
}
