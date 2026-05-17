<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Psr\Log\LoggerInterface;

/**
 * Per-user recurring-detection sweep. Runs the snooze-expiry pass
 * first (flipping `snoozed` rows back to `pending` once their
 * `snoozed_until` has elapsed) and then iterates every container-
 * tagged `recurring.detector` implementation against the user's
 * detection window.
 *
 * Concurrency contract:
 *  - `ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = userId`
 *    collapses a same-day re-dispatch (scheduled tick or the on-demand
 *    /recurring re-detect button) into a single queued pass; the lock
 *    releases the moment a worker begins `handle()`.
 *  - `tries = 3` + `backoff = [60, 300, 900]` keeps a transient queue
 *    or DB hiccup from final-failing the sweep without the prior two
 *    retries.
 *
 * Single permitted facade exception: the `Cache::driver('redis')`
 * call inside `uniqueVia()`. Laravel resolves the lock store at
 * queue-push time before constructor DI completes — a constructor-
 * injected `Repository` is not an option. The
 * `tests/Contracts/BoundaryArchTest.php` `noFacadeCallsFromRecurring`
 * carve-out array names this FQN explicitly.
 *
 * Dispatched daily from `routes/console.php` via the
 * `recurring.detect` scheduler entry, and on demand from the
 * `/recurring` re-detect button. The sweep runs read-mostly against
 * `transactions` and writes only to the Recurring-owned tables —
 * the `noTransactionWritesFromRecurring` arch invariant blocks any
 * cross-module write.
 */
final class DetectRecurringSeriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return Cache::driver('redis');
    }

    /**
     * @param  iterable<SeriesDetector>  $detectors  container-tagged `recurring.detector`
     * @param  LoggerInterface|null  $logger  optional PSR-3 logger for defensive
     *                                        warnings on schema-impossible row shapes. The Laravel queue
     *                                        worker auto-injects from the container; bare-handle test
     *                                        callers can omit it and the warnings degrade to a silent
     *                                        continue (same behaviour the legacy guard had).
     */
    public function handle(
        DatabaseManager $db,
        Clock $clock,
        iterable $detectors,
        RecurringSeriesStateMachine $stateMachine,
        ?LoggerInterface $logger = null,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        $this->expireSnoozes($db, $clock, $stateMachine, $logger, $user);

        foreach ($detectors as $detector) {
            $detector->detectForUser($user);
        }
    }

    private function expireSnoozes(
        DatabaseManager $db,
        Clock $clock,
        RecurringSeriesStateMachine $stateMachine,
        ?LoggerInterface $logger,
        User $user,
    ): void {
        $now = $clock->now()->toDateTimeString();
        $rows = $db->connection()->table('recurring_series')
            ->select(['id'])
            ->where('user_id', $user->id)
            ->where('state', 'snoozed')
            ->where('snoozed_until', '<=', $now)
            ->get();

        foreach ($rows as $row) {
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            if ($id === 0) {
                // The schema's autoincrement PK makes a numeric 0 id
                // structurally impossible — logging this rather than
                // silently continuing turns a schema corruption into a
                // visible warning instead of a no-op.
                if ($logger !== null) {
                    $logger->warning(
                        'DetectRecurringSeriesJob: encountered non-numeric recurring_series.id during snooze expiry; skipping.',
                        ['user_id' => $user->id, 'row' => (array) $row],
                    );
                }

                continue;
            }
            /** @var RecurringSeries $series */
            $series = RecurringSeries::query()->findOrFail($id);
            $stateMachine->transition($series, 'pending', 'snooze_expired', 'detector');
        }
    }
}
