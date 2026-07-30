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
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
use Throwable;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class SyncOpenBankingAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

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

        if ($connection === null) {
            // Deleted between dispatch and worker pickup — silently exit so
            // the queue does not retry forever against a row that no longer
            // exists.
            return;
        }

        $enabled = (bool) $connection->enabled;
        $consentExpiresAtRaw = $connection->consent_expires_at ?? null;
        $consentValid = is_string($consentExpiresAtRaw)
            && $consentExpiresAtRaw !== ''
            && CarbonImmutable::parse($consentExpiresAtRaw)->isFuture();

        if (! $enabled || ! $consentValid) {
            // No provider call, no timestamp write, for a connection that
            // is off or whose consent has expired.
            return;
        }

        $rawUserId = $connection->user_id ?? null;
        $userId = is_numeric($rawUserId) ? (int) $rawUserId : null;
        if ($userId === null) {
            return;
        }

        /** @var User|null $user */
        $user = User::query()->where('id', $userId)->first();
        if ($user === null) {
            return;
        }

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
                    // Deliberately not included: last_successful_sync_at —
                    // a failed attempt must never advance the freshness
                    // signal.
                    'last_attempt_at' => $now,
                    'last_attempt_status' => $isConsentFailure ? 'consent_failed' : 'error',
                    'updated_at' => $now,
                ]);

            if ($isConsentFailure) {
                $events->dispatch(new OpenBankingConsentFailed(
                    connectionId: $this->connectionId,
                    userId: $userId,
                    reason: substr($e->getMessage(), 0, 500),
                ));

                // Terminal — do not rethrow. Retrying an expired/revoked
                // consent cannot succeed until the user redoes the full
                // re-link dance.
                return;
            }

            throw $e;
        }
    }
}
