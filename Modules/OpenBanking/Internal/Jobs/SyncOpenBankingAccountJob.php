<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;
use Modules\OpenBanking\Internal\Support\ConsentWindow;

final class SyncOpenBankingAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    // Read by OpenBankingSyncRunner too: it re-takes this same key for the
    // duration of the fetch, which the queue's until-processing lock has
    // already released by the time handle() runs.
    public const int UNIQUE_FOR_SECONDS = 600;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(DatabaseManager $db, Clock $clock, OpenBankingSyncRunner $runner): void
    {
        $connection = $db->connection()
            ->table('open_banking_connections')
            ->where('id', $this->connectionId)
            ->first();

        $user = $this->resolveSyncableUser($connection, $clock->now());
        if ($user === null) {
            return;
        }

        $failure = $runner->runAndConfirm($this->connectionId, $user)->retryableFailure();
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function resolveSyncableUser(?\stdClass $connection, CarbonImmutable $now): ?User
    {
        if ($connection === null) {
            // Deleted between dispatch and pickup: exit rather than retry
            // forever against a row that no longer exists.
            return null;
        }

        $consent = ConsentWindow::fromStoredRow($connection, $now);

        $rawUserId = $connection->user_id ?? null;
        if (! (bool) $connection->enabled || ! $consent->isLive() || ! is_numeric($rawUserId)) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('id', (int) $rawUserId)->first();

        return $user;
    }
}
