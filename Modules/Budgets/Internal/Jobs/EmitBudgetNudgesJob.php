<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Jobs;

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
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Support\LockStore;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

final class EmitBudgetNudgesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(
        public readonly int $userId,
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

    public function handle(CarryoverQuery $carryover, PeriodQuery $periods, Dispatcher $events, AuthFactory $auth): void
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        /** @var array{period: Period, rows: array<int, EnvelopeRow>} $resolved */
        $resolved = $this->withGuardBoundTo($user, $auth, function () use ($carryover, $periods, $user): array {
            $period = $periods->current();

            /** @var array{toBudgetMinor: int, overspentCount: int, rows: array<int, EnvelopeRow>} $fold */
            $fold = $carryover->forUserAndPeriod($user, $period);

            return ['period' => $period, 'rows' => $fold['rows']];
        });

        $periodKey = $resolved['period']->start->toDateString();
        $rows = $resolved['rows'];

        foreach ($rows as $row) {
            $budgetMinor = $row->availableMinor + $row->spentMinor;

            if ($budgetMinor <= 0) {
                // No positive budget base -- nothing to be "over" for
                // this envelope this period.
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
                categorySlug: $row->categorySlug,
                categoryNameIsDefault: $row->categoryNameIsDefault,
            ));
        }
    }

    /**
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
