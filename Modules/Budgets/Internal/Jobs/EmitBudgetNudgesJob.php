<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Ledger\Public\Dto\Period;

/**
 * Per-user hourly evaluation of every envelope's live over-budget notify
 * threshold (Req 6). Reads the LIVE envelope model via
 * `CarryoverQuery::forUserAndPeriod()` — NOT the write-dead `category_budgets`
 * / `BudgetProgressQuery` progress path (D-20 REDIRECT, see 18-07's
 * `<planner_decisions>`). Dispatches one `BudgetThresholdCrossed` per
 * over-threshold envelope; Budgets never imports the notifications module's
 * namespace (D-28) — the event crosses the module boundary on its own.
 *
 * Clones `SafetyNetAnomalySweepJob`'s per-user job shape and
 * `CounterpartyGarbageCollectorJob`'s `ShouldBeUniqueUntilProcessing` triad
 * verbatim.
 *
 * Threshold math (D-20 REDIRECT): a row is over its threshold when
 * `spentMinor * 100 >= notifyThresholdPercent * (availableMinor +
 * spentMinor)`, guarding the `(availableMinor + spentMinor) <= 0` case (no
 * positive budget base -> no nudge, Req 6's negative case). Integer math on
 * the minor-unit fields only — never a float, never hand-rolled money.
 * `notifyThresholdPercent` is already null-coalesced to the D-20 90% default
 * by `CarryoverQuery` — this job never re-applies that default.
 *
 * Period computation: `CarryoverQuery::forUserAndPeriod()` needs a `Period`
 * for "now", but `Modules\Ledger\Public\Services\PeriodQuery::current()` is
 * bound to the request-scoped `CurrentUser` contract (the authenticated web
 * guard user) — there is no authenticated guard user in a queue/console
 * context. This job therefore reproduces `PeriodQuery::containing()`'s exact
 * period-window algorithm inline, scoped explicitly to the loaded `$user`'s
 * own `period_start_day` (clamped 1..28, `subMonthNoOverflow`/
 * `addMonthNoOverflow` boundaries) rather than inventing a new period
 * format — the SAME algorithm, just sourced from the explicit user instead
 * of the request-bound `CurrentUser`.
 *
 * Explicit user scoping on every query (T-18-25 / D-25): the job holds one
 * `int $userId` and never batches across users — `BelongsToUser`'s
 * `UserScope` global scope does not fire in queue/console context.
 *
 * No transaction wraps the dispatch loop (D-28 / WR-06) — each
 * `BudgetThresholdCrossed` dispatch is independent of any DB write here.
 */
final class EmitBudgetNudgesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(CarryoverQuery $carryover, Clock $clock, Dispatcher $events): void
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $period = $this->currentPeriodFor($user, $clock);
        $periodKey = $period->start->toDateString();

        $fold = $carryover->forUserAndPeriod($user, $period);

        /** @var array<int, EnvelopeRow> $rows */
        $rows = $fold['rows'];

        foreach ($rows as $row) {
            $budgetMinor = $row->availableMinor + $row->spentMinor;

            if ($budgetMinor <= 0) {
                // No positive budget base — nothing to be "over" (Req 6's
                // negative case).
                continue;
            }

            if ($row->spentMinor * 100 < $row->notifyThresholdPercent * $budgetMinor) {
                continue;
            }

            $events->dispatch(new BudgetThresholdCrossed(
                userId: $this->userId,
                categoryId: $row->categoryId,
                categoryName: $row->categoryName,
                period: $periodKey,
                thresholdPercent: $row->notifyThresholdPercent,
                spentMinor: $row->spentMinor,
                budgetMinor: $budgetMinor,
                currency: $row->currency,
            ));
        }
    }

    /**
     * Mirrors `PeriodQuery::containing()`'s exact window algorithm, scoped
     * to `$user->period_start_day` directly instead of the request-bound
     * `CurrentUser` contract (see class docblock).
     */
    private function currentPeriodFor(User $user, Clock $clock): Period
    {
        $instant = $clock->now();
        $startDay = max(1, min(28, $user->period_start_day));

        $candidate = $instant->setDay($startDay)->startOfDay();
        $start = $instant->day >= $startDay
            ? $candidate
            : $candidate->subMonthNoOverflow();
        $endExclusive = $start->addMonthNoOverflow();

        $label = $startDay === 1
            ? $start->format('F Y')
            : $start->format('j M').' → '.$endExclusive->subDay()->format('j M Y');

        return new Period(start: $start, endExclusive: $endExclusive, label: $label);
    }
}
