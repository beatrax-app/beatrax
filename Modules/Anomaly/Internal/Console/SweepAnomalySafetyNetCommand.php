<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Anomaly\Internal\Jobs\SafetyNetAnomalySweepJob;
use Modules\Core\Models\User;

// The safety net under the reactive TransactionImported listener: it
// re-evaluates recently-imported-but-unalerted transactions through the same
// AnomalyEvaluator, catching any charge the listener missed.
final class SweepAnomalySafetyNetCommand extends Command
{
    /** @var string */
    protected $signature = 'anomaly:safety-net-sweep';

    /** @var string */
    protected $description = 'Re-evaluate recently imported, unalerted transactions for every user.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->lazyById(100)->each(function (User $user): void {
            $this->bus->dispatch(new SafetyNetAnomalySweepJob($user->id));
        });

        return self::SUCCESS;
    }
}
