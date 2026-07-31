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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Modules\Position\Public\Services\PositionQuery;

/**
 * @link ../../../../.docs/features/position/architecture.md
 */
final class EmitPositionDigestJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

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

        // Dispatch unconditionally — no "is anything interesting?" gate.
        // The digest itself is the reassurance the notification carries.
        $events->dispatch(new PositionDigestDue(
            userId: $this->userId,
            cadence: $this->cadence,
            occurrence: $occurrence,
            position: $position,
        ));
    }

    /**
     * @link ../../../../.docs/features/position/architecture.md
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
