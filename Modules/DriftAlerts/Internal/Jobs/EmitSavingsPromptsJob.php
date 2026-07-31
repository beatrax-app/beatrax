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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Support\LockStore;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
final class EmitSavingsPromptsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
