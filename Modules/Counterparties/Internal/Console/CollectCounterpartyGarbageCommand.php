<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;

// The orphan predicate keeps any counterparty with recent transactions or an
// alias anchor, so this only ever removes rows nothing points at.
final class CollectCounterpartyGarbageCommand extends Command
{
    /** @var string */
    protected $signature = 'counterparties:collect-garbage';

    /** @var string */
    protected $description = 'Prune orphaned counterparties for every user.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->lazyById(100)->each(function (User $user): void {
            $this->bus->dispatch(new CounterpartyGarbageCollectorJob($user->id));
        });

        return self::SUCCESS;
    }
}
