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
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
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
     * @return bool true when the candidate's expected charge already landed —
     *              occurrencesForSeries() returns rows ordered observed_at DESC, so the first entry is
     *              the most recent real transaction against this series; if it fell on or after the due
     *              date, that charge has already been matched and the detector simply hasn't re-swept
     *              next_expected_at forward yet
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
