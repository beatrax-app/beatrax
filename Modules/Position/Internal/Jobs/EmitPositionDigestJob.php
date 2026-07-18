<?php

declare(strict_types=1);

namespace Modules\Position\Internal\Jobs;

use Illuminate\Auth\SessionGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Modules\Position\Public\Services\PositionQuery;

/**
 * Cadence-aware position digest emission (Req 5).
 *
 * The cadence is a CONSTRUCTOR PARAMETER, resolved by the scheduler entry
 * (plan 18-14) which reads the Notifications module's per-device
 * preferences and dispatches this job — this module itself never reads
 * that other module's preference-query service; doing so would violate
 * D-38 invariant 1 (Position must stay ignorant of Notifications).
 *
 * Body (D-21 / D-06):
 *   1. `cadence === 'off'` -> return immediately, dispatch nothing (Req 5's
 *      'off' case).
 *   2. Compute the D-06 occurrence key from the injected `Clock` (never
 *      `now()` directly, so `CarbonImmutable::setTestNow()` drives it).
 *   3. `PositionQuery::forUser()`.
 *   4. Dispatch exactly ONE `PositionDigestDue` — UNCONDITIONALLY, even
 *      when nothing is notable. There is no "is anything interesting?"
 *      gate: the digest IS the ritual (D-21), and adding one would make
 *      Req 5's cadence acceptance test ambiguous.
 *
 * Per-user job shape cloned from `SafetyNetAnomalySweepJob` +
 * `CounterpartyGarbageCollectorJob`'s `ShouldBeUniqueUntilProcessing` triad
 * verbatim. `uniqueId()` includes the cadence so a daily and a weekly run
 * for the same user can never lock each other out.
 *
 * Period resolution: `PositionQuery::forUser()` needs a `Period`, and
 * (transitively, via `BudgetProgressQuery::forCurrentPeriod()`) resolves
 * ANOTHER `Period` of its own — both paths go through
 * `Modules\Ledger\Public\Services\PeriodQuery`, which is bound to the
 * request-scoped `CurrentUser` contract (the authenticated web guard user).
 * There is no authenticated guard user in a queue/console context. This job
 * therefore reuses `EmitBudgetNudgesJob`'s exact precedent (18-07): for the
 * duration of the composition call only, the loaded `$user` is bound onto
 * the default auth guard (`CurrentUserService` is a thin, uncached
 * pass-through to that guard, so every `CurrentUser` read reached
 * transitively during the call resolves to `$user`), and the guard's prior
 * state is restored in a `finally` block — this job never leaves a queue
 * worker process with another user's identity bound to the guard after it
 * returns.
 *
 * Explicit user scoping throughout; no transaction wraps the dispatch
 * (D-28 / WR-06) — the event dispatch happens strictly after
 * `PositionQuery`'s reads, never inside an open write transaction.
 */
final class EmitPositionDigestJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        public readonly string $cadence,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->cadence;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        Clock $clock,
        PositionQuery $positionQuery,
        PeriodQuery $periods,
        Dispatcher $events,
        AuthFactory $auth,
    ): void {
        if ($this->cadence === 'off') {
            return;
        }

        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $now = $clock->now();
        $occurrence = $this->cadence === 'daily'
            ? $now->toDateString()
            : $now->isoWeekYear.'-W'.str_pad((string) $now->isoWeek, 2, '0', STR_PAD_LEFT);

        $position = $this->withGuardBoundTo(
            $user,
            $auth,
            static function () use ($positionQuery, $periods, $user): PositionSummaryDto {
                $period = $periods->current();

                return $positionQuery->forUser($user, $period);
            },
        );

        // D-21: dispatch UNCONDITIONALLY — no "is anything interesting?"
        // gate. The digest IS the ritual; "nothing notable" is itself the
        // reassurance the notification carries.
        $events->dispatch(new PositionDigestDue(
            userId: $this->userId,
            cadence: $this->cadence,
            occurrence: $occurrence,
            position: $position,
        ));
    }

    /**
     * Runs `$callback` with `$user` bound to the default auth guard, so any
     * `CurrentUser` read reached transitively during the call (via
     * `PositionQuery` -> `PeriodQuery` / `BudgetProgressQuery` ->
     * `CurrentUserService` -> the guard) resolves to `$user` rather than
     * throwing `NotAuthenticatedException`. Always restores the guard's
     * prior state in a `finally` — a real previous user is set back via
     * `setUser()`; an unauthenticated prior state is cleared back via
     * `SessionGuard::forgetUser()` when the resolved guard is the app's
     * configured session guard (the only guard driver this project runs),
     * otherwise left as-is rather than risking an unsupported mutation on
     * an unknown guard implementation. Cloned verbatim from
     * `EmitBudgetNudgesJob` (18-07).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withGuardBoundTo(User $user, AuthFactory $auth, callable $callback): mixed
    {
        /** @var Guard $guard */
        $guard = $auth->guard();
        $previousUser = $guard->user();

        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previousUser !== null) {
                $guard->setUser($previousUser);
            } elseif ($guard instanceof SessionGuard) {
                $guard->forgetUser();
            }
        }
    }
}
