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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Support\LockStore;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final class EmitPaymentRemindersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

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
        return Duration::Hour->seconds();
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(RecurringSeriesQuery $query, RecurringOccurrenceQuery $occurrences, Dispatcher $events, Clock $clock): void
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $today = $clock->now()->startOfDay();
        $windowEnd = $today->addDays(max(0, $this->leadDays));

        $due = [];
        foreach ($query->allApprovedForUser($user) as $series) {
            $at = $series->nextExpectedAt;
            if ($at === null || $at->lt($today) || $at->gt($windowEnd)) {
                continue;
            }
            $due[$series->seriesId] = [$series, $at];
        }

        // One SELECT for the whole sweep, not one per approved series: the
        // only thing this needs from the observations is the newest date.
        $latestObserved = $occurrences->latestObservedAtForSeriesIds(array_keys($due), $user);

        foreach ($due as $seriesId => [$series, $at]) {
            if (self::alreadySettled($latestObserved[$seriesId] ?? null, $at)) {
                continue;
            }

            $events->dispatch(new PaymentReminderDue(
                userId: $this->userId,
                seriesId: $series->seriesId,
                dueDate: $at,
                confidenceLow: $series->nextExpectedConfidenceLow,
                expectedAmount: $series->latestAmount,
                displayName: $series->displayName(),
            ));
        }
    }

    // True when the expected charge already landed: the newest real
    // transaction against this series fell on or after the due date, and the
    // detector simply has not re-swept next_expected_at forward yet.
    private static function alreadySettled(?string $latestObserved, CarbonImmutable $due): bool
    {
        return $latestObserved !== null && $latestObserved >= $due->toDateString();
    }
}
