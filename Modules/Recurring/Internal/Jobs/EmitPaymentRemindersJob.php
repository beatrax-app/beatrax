<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
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
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Per-user scheduled reminder evaluation (Req 4). Emits `PaymentReminderDue`
 * for every approved recurring series whose `nextExpectedAt` falls inside
 * `[today, today + leadDays]` inclusive, skipping any candidate the Req-13
 * before-fire settlement check finds already paid.
 *
 * `$leadDays` is a CONSTRUCTOR PARAMETER, not read from
 * `NotificationPreferenceQuery` in here — this module reading the
 * notification-preferences Public service would violate D-38 invariant 1
 * (no trigger module may import the notification store's package). The
 * scheduler entry (plan 18-14) reads the device's lead-time preference and
 * passes it in, keeping this module wholly ignorant of notification
 * delivery concerns.
 *
 * Dispatch happens outside any open transaction — a queued job body is not
 * wrapped in one — satisfying D-28/WR-06's emit-after-commit contract for
 * free; the per-candidate loop below deliberately never opens one.
 *
 * Concurrency contract mirrors `SafetyNetAnomalySweepJob` /
 * `CounterpartyGarbageCollectorJob`: `ShouldBeUniqueUntilProcessing` keyed
 * on `uniqueId() = (string) userId` collapses a same-window duplicate
 * dispatch (T-18-24 — two independent schedulers, desktop + mobile) into a
 * single queued run per user; `tries = 3` + `backoff = [60, 300, 900]`
 * tolerates a transient hiccup. Even if both schedulers evaluate the same
 * series, D-05's deterministic notification PK collapses the eventual
 * result to one inbox row regardless (Req 12) — correctness never depends
 * on this lock.
 *
 * Cross-user discipline (T-18-21): every query the job issues carries an
 * explicit `where('user_id', ...)` / `$user` argument via
 * `RecurringSeriesQuery` — `BelongsToUser`'s `UserScope` global scope does
 * not fire in queue/console context. The job holds a single `int $userId`
 * and never batches across users.
 */
final class EmitPaymentRemindersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        public readonly int $leadDays,
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

    public function handle(RecurringSeriesQuery $query, Dispatcher $events, Clock $clock): void
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $today = $clock->now()->startOfDay();
        $windowEnd = $today->addDays(max(0, $this->leadDays));

        foreach ($query->allApprovedForUser($user) as $series) {
            $due = $series->nextExpectedAt;
            if ($due === null) {
                continue;
            }

            if ($due->lt($today) || $due->gt($windowEnd)) {
                continue;
            }

            if ($this->alreadySettled($query, $series, $user, $due)) {
                continue;
            }

            $events->dispatch(new PaymentReminderDue(
                userId: $this->userId,
                seriesId: $series->seriesId,
                dueDate: $due,
                confidenceLow: $series->nextExpectedConfidenceLow,
                expectedAmount: $series->latestAmount,
                displayName: $series->displayName(),
            ));
        }
    }

    /**
     * Req 13's before-fire settlement check: skip a candidate whose
     * expected charge has already landed. `occurrencesForSeries()` returns
     * rows ordered `observed_at DESC`, so the first entry is the most
     * recent real transaction recorded against this series — if it fell on
     * or after the due date, that due date's charge has already been
     * matched and the detector simply has not re-swept `next_expected_at`
     * forward yet. Uses the query's real shape rather than inventing a new
     * one, per this plan's locked decision.
     */
    private function alreadySettled(
        RecurringSeriesQuery $query,
        RecurringSeriesDto $series,
        User $user,
        CarbonImmutable $due,
    ): bool {
        $occurrences = $query->occurrencesForSeries($series->seriesId, $user);
        if ($occurrences === []) {
            return false;
        }

        return $occurrences[0]->observedAt->toDateString() >= $due->toDateString();
    }
}
