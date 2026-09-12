<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
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
    protected $description = 'Re-evaluate recently imported, unalerted transactions, and resume any backfill that stopped short.';

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $owed = $this->usersOwedTheRestOfTheirHistory();

        User::query()->lazyById(100)->each(function (User $user) use ($owed): void {
            $this->bus->dispatch(new SafetyNetAnomalySweepJob($user->id));

            if (isset($owed[$user->id])) {
                $this->bus->dispatch(new BackfillAnomaliesJob($user->id));
            }
        });

        return self::SUCCESS;
    }

    // The sweep beside this one only looks back thirty days, so it is no net at
    // all under a first-activation walk over years of history. A walk that was
    // claimed and never completed is picked up here instead, from the chunk it
    // reached; the job itself refuses while another runner still holds it.
    /**
     * @return array<int, true>
     */
    private function usersOwedTheRestOfTheirHistory(): array
    {
        $owed = [];

        /** @var mixed $userId */
        foreach (
            $this->db->connection()->table('anomaly_backfill_state')
                ->whereNull('completed_at')
                ->pluck('user_id') as $userId
        ) {
            if (is_numeric($userId)) {
                $owed[(int) $userId] = true;
            }
        }

        return $owed;
    }
}
