<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LockStore;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;

/**
 * Per-user push of the EXISTING SEED-010 savings insights (Req 7) —
 * deliberately the smallest of the four proactive triggers, because it
 * computes nothing. Reads `SavingsInsightsQuery::forUser($user)` — the same
 * read `SavingsInsightsCard` uses — and dispatches one `SavingsPromptDue`
 * event per returned insight. `forUser()` already excludes any insight the
 * user has dismissed, so this job MUST NOT re-filter: a second filter here
 * would let the notification surface and the card silently drift apart on
 * what is "live" (T-18-31).
 *
 * Clones `SafetyNetAnomalySweepJob`'s per-user job shape and
 * `CounterpartyGarbageCollectorJob`'s `ShouldBeUniqueUntilProcessing` triad
 * verbatim.
 *
 * Explicit user scoping (T-18-29 / D-25): the job holds one `int $userId`
 * and never batches across users — `BelongsToUser`'s `UserScope` global
 * scope does not fire in queue/console context, and `SavingsInsightsQuery`
 * is already user-scoped by its own discipline.
 *
 * No transaction wraps the dispatch loop (D-28 / WR-06) — each
 * `SavingsPromptDue` dispatch is independent of any DB write here.
 */
final class EmitSavingsPromptsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    public function handle(SavingsInsightsQuery $insights, Dispatcher $events): void
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        foreach ($insights->forUser($user) as $insight) {
            $events->dispatch(new SavingsPromptDue(
                userId: $this->userId,
                insightKey: $insight->key,
                seriesId: $insight->seriesId,
                name: $insight->name,
                monthlyMinor: $insight->monthlyMinor,
                currency: $insight->currency,
                message: $insight->message,
                actionUrl: $insight->actionUrl,
            ));
        }
    }
}
