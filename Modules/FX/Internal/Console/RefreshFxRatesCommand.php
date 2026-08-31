<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;

// Only users who opted into online rate fetching trigger a job, so a
// local-only install never makes an outbound request from this command.
// `lazyById` so a multi-user deployment never loads every row up front.
final class RefreshFxRatesCommand extends Command
{
    /** @var string */
    protected $signature = 'fx:refresh-rates';

    /** @var string */
    protected $description = 'Fetch today\'s FX rates for every user who enabled online rate fetching.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->lazyById(100)->each(function (User $user): void {
            if ($user->fx_online_enabled) {
                $this->bus->dispatch(new FetchFxRatesJob($user->id));
            }
        });

        return self::SUCCESS;
    }
}
