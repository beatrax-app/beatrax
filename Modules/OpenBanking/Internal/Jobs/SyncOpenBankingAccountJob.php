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
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
use Throwable;

/**
 * Per-connection scheduler + "Sync now" fetch target (D-13). Role-matches
 * `Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob`
 * (19-PATTERNS.md): a thin per-row job whose real work lives in a Public
 * service (`OpenBankingFetchService`), with `ShouldBeUniqueUntilProcessing`
 * keyed on the row id so a second dispatch for the SAME connection cannot
 * race a still-running one.
 *
 * Two-timestamp accounting (RESEARCH.md Pitfall 5 — the load-bearing
 * invariant this job exists to protect): `last_successful_sync_at` is
 * written ONLY in the success branch, never in a `finally`. Every attempt
 * (success or failure) writes `last_attempt_at` + `last_attempt_status`,
 * so a failing scheduled sync is visible independently of "when did data
 * last actually refresh" (Req 7's "never present stale data as fresh").
 *
 * Defensive re-check on pickup (RESEARCH.md §6 / `IncrementalScanJob`'s
 * "Early exit #1" — the same crash-between-dispatch-and-pickup race that
 * docblock documents): the scheduler's `open-banking.daily-sync`
 * enumeration query (a later wave) already filters
 * `WHERE enabled AND consent_expires_at > now()`, but a race where the
 * user disables OB or the consent expires while this job sits queued must
 * still no-op here — NO fetch attempt and NO timestamp write at all for a
 * disabled/expired connection (Req 9 acceptance).
 *
 * Consent-failure detection mirrors `IncrementalScanJob`'s
 * `InvalidGrantException` branch: a 401/403 from Enable Banking
 * (`EnableBankingHttpClient::mapErrorResponse()`'s `"HTTP {status}"`
 * message fragment — no typed exception exists for this distinction yet,
 * so the message is inspected) is TERMINAL for this attempt (retrying
 * cannot succeed until the user redoes the full re-link dance) and
 * dispatches `OpenBankingConsentFailed` for 19-06's alert listener. Any
 * other failure (network, parse, DB) rethrows so the queue's `tries`/
 * `backoff` retry envelope applies, matching every other job's
 * generic-Throwable branch in this codebase.
 */
final class SyncOpenBankingAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry attempts before final failure. */
    public int $tries = 3;

    /**
     * Exponential backoff in seconds.
     *
     * @var array<int, int>
     */
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
            // Connection was deleted between dispatch and worker pickup —
            // silently exit so the queue does not retry forever against a
            // row that no longer exists.
            return;
        }

        $enabled = (bool) $connection->enabled;
        $consentExpiresAtRaw = $connection->consent_expires_at ?? null;
        $consentValid = is_string($consentExpiresAtRaw)
            && $consentExpiresAtRaw !== ''
            && CarbonImmutable::parse($consentExpiresAtRaw)->isFuture();

        if (! $enabled || ! $consentValid) {
            // Early exit — mirrors IncrementalScanJob's needs_reauth
            // early exit: no provider call, no timestamp write, for a
            // connection that is off or whose consent has expired.
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
            $isConsentFailure = self::isConsentFailure($e);

            $db->connection()
                ->table('open_banking_connections')
                ->where('id', $this->connectionId)
                ->update([
                    // Deliberately NOT included: last_successful_sync_at.
                    // A failed attempt must never advance the freshness
                    // signal — this is the single invariant this job
                    // exists to protect (RESEARCH.md Pitfall 5).
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
                // re-link dance (same rationale as IncrementalScanJob's
                // InvalidGrantException branch).
                return;
            }

            throw $e;
        }
    }

    /**
     * No typed exception distinguishes a 401/403 consent failure from
     * any other Enable Banking error today — `EnableBankingHttpClient::
     * mapErrorResponse()` embeds the HTTP status in a generic
     * `RuntimeException` message (`"... returned HTTP {status} — ..."`).
     * Inspecting the message is the narrowest fix available without
     * widening this plan's file scope to introduce a new typed
     * exception across the HTTP client boundary.
     */
    private static function isConsentFailure(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 401') || str_contains($message, 'HTTP 403');
    }
}
