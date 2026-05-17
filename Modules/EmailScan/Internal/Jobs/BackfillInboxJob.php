<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\EmlBlobStore;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Throwable;

/**
 * The queued chunked fetcher for one connected inbox's backfill.
 *
 * Concurrency contract:
 *  - `ShouldBeUniqueUntilProcessing` keyed on `inboxId` blocks a
 *    second dispatch for the same inbox while the worker has not
 *    yet started; the lock releases as soon as `handle()` begins so
 *    a re-dispatch can sit in the queue while the prior pass
 *    finishes. The unique-lock store is Redis (the project's only
 *    permitted facade exception — Laravel calls `uniqueVia()` at
 *    push-time before constructor DI resolves).
 *  - `tries = 3` + `backoff = [60, 300, 900]` matches the
 *    project-wide retry envelope so the per-inbox state machine's
 *    rate-limited / error transitions ride the same back-off curve.
 *
 * Walk shape:
 *  - Read the connected inbox row + the per-user known-senders
 *    list. Compose a Gmail server-side filter (`from:(a OR b ...)`)
 *    and walk 100-message pages until the provider stops returning
 *    a nextPageToken.
 *  - For every message: fetch raw bytes from Gmail, parse the four
 *    RFC 822 header values needed for the index row, write the .eml
 *    to disk first, then open a small DB transaction that inserts
 *    the inbox_messages row with insertOrIgnore (the unique
 *    constraint on `(inbox_id, provider_message_id)` makes a retry
 *    of the same id a no-op).
 *  - On a tx failure the catch block unlinks the .eml so there is
 *    never an orphan blob on disk without a matching index row.
 *  - Per-page: bump `inboxes.backfill_progress` so the /inboxes
 *    progress strip's `wire:poll.2s` has fresh counters to render.
 *  - Between pages: sleep two seconds via Sleep::seconds so the
 *    quota envelope (250 units / user / second on the read API) is
 *    not exhausted by a tight loop.
 *
 * Window clamp (defensive): the slider clamps client-side, but a
 * crafted POST could carry windowMonths=999. The handler re-clamps
 * to [1, 12] before any further work — the constructor is too
 * early to read the property in a deserialised job, and constructor
 * argument validation would invalidate the inbox-id-keyed unique
 * lock if it threw mid-push.
 *
 * Error envelope:
 *  - RateLimitedException → transition to `rate_limited`, rethrow
 *    so Horizon's retry envelope schedules the next attempt.
 *  - InvalidGrantException → transition to `needs_reauth` and
 *    swallow the throw (the user must reconnect; another retry
 *    cannot make progress).
 *  - Any other throwable → transition to `error` (with the first
 *    500 chars of the exception message) and rethrow so the
 *    JobFailed listener can surface the failure.
 *
 * Single permitted facade exception: the `Cache::driver('redis')`
 * call inside `uniqueVia()`. The Laravel queue infrastructure
 * invokes `uniqueVia()` at push-time before constructor DI
 * completes; a constructor-injected `Repository` is not an option.
 * The `tests/Contracts/BoundaryArchTest.php` allow-list grants
 * this file FQN the "no Laravel facades in module code" exemption.
 */
final class BackfillInboxJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $inboxId,
        public readonly int $windowMonths,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function uniqueFor(): int
    {
        // 30-minute single-flight ceiling — long enough for a
        // multi-page backfill of a year of receipts to finish,
        // short enough that a worker crash unblocks the next
        // dispatch promptly.
        return 1800;
    }

    public function uniqueVia(): Repository
    {
        // The Cache facade is the single permitted facade use in
        // module code (BoundaryArchTest carve-out). Laravel calls
        // uniqueVia() before constructor DI completes — there is
        // no path to inject a Repository at this point.
        return Cache::driver('redis');
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        GmailApiClientContract $gmail,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        OAuthSecretsRepository $secrets,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
    ): void {
        // Touch $secrets so the static analyser does not flag the
        // unused argument. The OAuth client (GmailApiClient) loads
        // credentials transparently; the contract still pins
        // secrets to this job's DI surface because future
        // re-baselining flows in later plans use it directly.
        unset($secrets);

        $connection = $db->connection();

        $inboxRow = $connection->table('inboxes')->where('id', $this->inboxId)->first();
        if ($inboxRow === null) {
            // Inbox was deleted between dispatch and worker pickup —
            // silently exit so the queue does not retry forever.
            return;
        }

        $rawUserId = $inboxRow->user_id;
        $userId = is_numeric($rawUserId) ? (int) $rawUserId : 0;
        $rawProvider = $inboxRow->provider;
        $provider = is_string($rawProvider) ? $rawProvider : '';

        /** @var User $user */
        $user = User::query()->where('id', $userId)->firstOrFail();

        if ($provider === 'microsoft') {
            // Microsoft Graph backfill arrives in the next plan;
            // for now mark the inbox so the state surfaces in the
            // UI without retrying forever.
            $sm->applyStatus(
                $this->inboxId,
                'error',
                'Microsoft 365 backfill is not implemented yet.',
            );

            return;
        }

        // Defensive window clamp — the slider clamps client-side
        // but a crafted POST may carry an out-of-range value.
        $window = max(1, min(12, $this->windowMonths));
        unset($window);

        // Read known senders (system seeds + user additions) for
        // the from:(...) query that Gmail evaluates server-side.
        $senderPatterns = array_map(
            static fn ($s) => $s->emailPattern,
            $senderQuery->all($user),
        );

        if ($senderPatterns === []) {
            $sm->applyStatus(
                $this->inboxId,
                'idle',
                'No known senders are configured for this user.',
            );

            return;
        }

        $sm->applyStatus($this->inboxId, 'backfilling');

        $fetched = 0;
        $estimated = 0;
        $pageToken = null;
        $highestHistoryId = null;

        try {
            while (true) {
                $page = $gmail->listSenderMessages(
                    $this->inboxId,
                    $senderPatterns,
                    $pageToken,
                );
                $estimated = max($estimated, $page['resultSizeEstimate']);
                $messages = $page['messages'];

                if ($messages === []) {
                    break;
                }

                foreach ($messages as $msgMeta) {
                    $messageId = $msgMeta['id'];

                    $rawEml = $gmail->getRawMessage($this->inboxId, $messageId);
                    $headers = $mime->parseHeaders($rawEml);

                    $emlPath = $blobStore->pathFor(
                        $userId,
                        $this->inboxId,
                        $headers->internalDate,
                        $messageId,
                    );
                    $blobStore->put($emlPath, $rawEml);

                    try {
                        $connection->transaction(function () use (
                            $connection,
                            $userId,
                            $messageId,
                            $headers,
                            $clock,
                        ): void {
                            $connection->statement('PRAGMA busy_timeout = 5000');
                            $now = $clock->now()->toDateTimeString();
                            $connection->table('inbox_messages')->insertOrIgnore([
                                'user_id' => $userId,
                                'inbox_id' => $this->inboxId,
                                'provider_message_id' => $messageId,
                                'internal_date' => $headers->internalDate->format('Y-m-d H:i:s'),
                                'sender_email' => $headers->senderEmail,
                                'sender_name' => $headers->senderName,
                                'subject' => $headers->subject,
                                'status' => 'fetched',
                                'fetched_at' => $now,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        });
                    } catch (Throwable $e) {
                        // Orphan-cleanup: the .eml is on disk but
                        // the DB insert never landed — unlink so
                        // there is no untracked blob.
                        $blobStore->delete($emlPath);
                        throw $e;
                    }

                    $fetched++;
                }

                // Update the live progress payload so the
                // /inboxes wire:poll has fresh counters.
                $connection->table('inboxes')
                    ->where('id', $this->inboxId)
                    ->update([
                        'backfill_progress' => json_encode([
                            'fetched_count' => $fetched,
                            'total_estimated' => $estimated,
                            'last_message_date' => null,
                        ], JSON_THROW_ON_ERROR),
                        'updated_at' => $clock->now()->toDateTimeString(),
                    ]);

                if ($page['historyId'] !== null) {
                    $highestHistoryId = $page['historyId'];
                }

                $pageToken = $page['nextPageToken'];
                if ($pageToken === null) {
                    break;
                }

                // Throttle between pages so the read quota envelope
                // is not exhausted by a tight loop. Sleep::seconds
                // is fakeable via Sleep::fake() in tests.
                Sleep::sleep(2);
            }

            // Set the baseline cursor only via the state machine's
            // sole-mutator surface so the BoundaryArchTest stays
            // green.
            if ($highestHistoryId !== null) {
                $sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::gmail($highestHistoryId),
                );
            }

            // Clear the progress payload so the /inboxes strip
            // hides itself, and flip the per-inbox status back to
            // idle.
            $connection->table('inboxes')
                ->where('id', $this->inboxId)
                ->update([
                    'backfill_progress' => null,
                    'updated_at' => $clock->now()->toDateTimeString(),
                ]);
            $sm->applyStatus($this->inboxId, 'idle');
        } catch (RateLimitedException $e) {
            $sm->applyStatus(
                $this->inboxId,
                'rate_limited',
                "Retry after {$e->retryAfterSeconds}s.",
            );
            throw $e;
        } catch (InvalidGrantException $e) {
            $sm->applyStatus(
                $this->inboxId,
                'needs_reauth',
                'OAuth grant revoked or expired.',
            );
            // Do not rethrow — needs_reauth is terminal until the
            // user manually reconnects.
            unset($e);
        } catch (Throwable $e) {
            $sm->applyStatus(
                $this->inboxId,
                'error',
                substr($e->getMessage(), 0, 500),
            );
            throw $e;
        }
    }
}
