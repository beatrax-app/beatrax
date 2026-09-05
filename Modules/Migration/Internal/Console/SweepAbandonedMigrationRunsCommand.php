<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\DiscardMigrationRun;

// The age threshold, the two never-confirmed states and the owner scope all
// belong to the action; this is only the walk that reaches every reader. It
// keeps the scope by asking per user rather than per run, so no query in the
// sweep is ever wider than one account.
final class SweepAbandonedMigrationRunsCommand extends Command
{
    /** @var string */
    protected $signature = 'migration:sweep-abandoned';

    /** @var string */
    protected $description = 'Reclaim the staging rows held by never-confirmed migration runs, for every user.';

    // The action is resolved on demand, never injected: Artisan builds every
    // command to assemble its list, and a graph built at that moment is held
    // for the life of the process with whatever configuration it froze.
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $discard = $this->container->make(DiscardMigrationRun::class);
        $reclaimed = 0;

        User::query()->lazyById(100)->each(function (User $user) use ($discard, &$reclaimed): void {
            $reclaimed += $discard->sweepAbandonedForUser($user);
        });

        $this->info(sprintf('Reclaimed %d abandoned migration run(s).', $reclaimed));

        return self::SUCCESS;
    }
}
