<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
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
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
use Throwable;

final class SyncOpenBankingAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        OpenBankingFetchService $fetchService,
        Dispatcher $events,
    ): void {
        $connection = $db->connection()
            ->table('open_banking_connections')
            ->where('id', $this->connectionId)
            ->first();

        $user = $this->resolveSyncableUser($connection);
        if ($user === null) {
            return;
        }

        $userId = $user->id;
        $now = $clock->now()->toDateTimeString();

        try {
            $fetchService->fetchAndConfirm($this->connectionId, $user);

            $db->connection()
                ->table('open_banking_connections')
                ->where('id', $this->connectionId)
                ->update([
                    'last_successful_sync_at' => $now,
                    'last_attempt_at' => $now,
                    'last_attempt_status' => 'ok',
                    'updated_at' => $now,
                ]);
        } catch (Throwable $e) {
            $isConsentFailure = EnableBankingApiException::consentFailureWithin($e);

            $db->connection()
                ->table('open_banking_connections')
                ->where('id', $this->connectionId)
                ->update([
                    // No last_successful_sync_at: a failed attempt must never
                    // advance the freshness signal.
                    'last_attempt_at' => $now,
                    'last_attempt_status' => $isConsentFailure ? 'consent_failed' : 'error',
                    'updated_at' => $now,
                ]);

            // A consent failure is terminal: no retry succeeds until the user
            // redoes the re-link dance. Everything else goes back to the queue.
            if (! $isConsentFailure) {
                throw $e;
            }

            $events->dispatch(new OpenBankingConsentFailed(
                connectionId: $this->connectionId,
                userId: $userId,
                reason: substr($e->getMessage(), 0, 500),
            ));
        }
    }

    private function resolveSyncableUser(?\stdClass $connection): ?User
    {
        if ($connection === null) {
            // Deleted between dispatch and pickup: exit rather than retry
            // forever against a row that no longer exists.
            return null;
        }

        $enabled = (bool) $connection->enabled;
        $consentExpiresAtRaw = $connection->consent_expires_at ?? null;
        $consentValid = is_string($consentExpiresAtRaw)
            && $consentExpiresAtRaw !== ''
            && CarbonImmutable::parse($consentExpiresAtRaw)->isFuture();

        $rawUserId = $connection->user_id ?? null;
        if (! $enabled || ! $consentValid || ! is_numeric($rawUserId)) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('id', (int) $rawUserId)->first();

        return $user;
    }
}
