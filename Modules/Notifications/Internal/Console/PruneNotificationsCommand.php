<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Jobs\PruneNotificationsJob;
use Modules\Notifications\Internal\Support\PerUserPass;

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
        private readonly PerUserPass $users,
    ) {
        parent::__construct();
    }

    // Through the walk that survives one reader, because the dispatch itself is
    // what throws here: the queue table is the same single-writer SQLite file
    // the sweep it queues will read, and a busy one used to leave every user
    // after the first contention with an inbox nothing ever bounded.
    public function handle(): int
    {
        $this->users->each($this->signature, function (User $user): void {
            $this->bus->dispatch(new PruneNotificationsJob($user->id));
        });

        return self::SUCCESS;
    }
}
