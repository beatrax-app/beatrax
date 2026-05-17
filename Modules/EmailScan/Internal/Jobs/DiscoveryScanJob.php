<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Throwable;

/**
 * Daily per-user broad-keyword discovery scan.
 *
 * Walks each connected inbox with a broad subject-keyword query
 * (`receipt OR factuur OR betaling OR invoice OR order OR bevestiging`)
 * minus the senders the user has already promoted to known_senders
 * OR explicitly dismissed via the /inboxes panel. New sender metadata
 * lands as `discovered_senders` rows in state='candidate'. The
 * promotion-threshold cap (2 occurrences within 90 days) lives in the
 * panel-rendering query (DiscoveredSenderQuery), NOT here — the job
 * collects every observation so the user has a complete picture in
 * the UI's "shown only above threshold" / "all observations" toggle
 * (the toggle itself is out of scope for Phase 6; the data shape
 * supports it).
 *
 * Discovery-loop invariants (D-121):
 *  - NO .eml blobs are persisted from this surface. The Gmail client
 *    calls `users.messages.get?format=metadata&metadataHeaders=['From','Date']`
 *    so only the From + Date headers cross the wire; the Graph client
 *    uses `$search` with `$select=id,from,subject,receivedDateTime`
 *    so the body never lands on the worker.
 *  - Dismissed and already-added senders are excluded from the query
 *    AND defensively re-filtered client-side; even if a provider's
 *    query syntax fails to honour the exclude list, the in-handler
 *    loop drops the message before any `discovered_senders` upsert.
 *  - Per-message sender_email is lowercased so case-different variants
 *    of the same address (e.g. `Sender@example.com` vs
 *    `sender@example.com`) collapse to one row via the UNIQUE
 *    constraint `(user_id, inbox_id, sender_email)` from Plan 02.
 *
 * Concurrency contract (mirrors BackfillInboxJob / IncrementalScanJob):
 *  - `ShouldBeUniqueUntilProcessing` keyed on `userId` blocks a second
 *    dispatch for the same user while the worker has not yet started.
 *    The unique-lock store is Redis (the single permitted module-code
 *    facade carve-out — Laravel calls `uniqueVia()` at push-time
 *    before constructor DI completes; the BoundaryArchTest carve-out
 *    grants this class the same exemption as the other two jobs).
 *  - `uniqueFor=600` (10 minutes) matches IncrementalScanJob —
 *    discovery completes in seconds-to-minutes per inbox and the
 *    lock should not linger if a worker crashes.
 *  - `tries=3` + `backoff=[60,300,900]` matches the project-wide
 *    retry envelope.
 *
 * Error envelope:
 *  - `RateLimitedException` on the discovery query → silently abort
 *    the daily run; the next day's scheduler tick will retry. The
 *    daily cadence is forgiving enough that an empty-handed retry
 *    tomorrow is cheaper than burning the per-inbox state-machine
 *    on a transient quota hit (no `.eml` blobs are involved so there
 *    is nothing to clean up).
 *  - Any other Throwable → log nothing (no facade access to Log in
 *    module code) and continue to the next inbox. Discovery is a
 *    best-effort surface — a single inbox's failure must not abort
 *    the per-user pass.
 *
 * Single permitted facade exception: `Cache::driver('redis')` inside
 * `uniqueVia()`. The Laravel queue infrastructure invokes
 * `uniqueVia()` at push-time before constructor DI completes; a
 * constructor-injected `Repository` is not an option. The
 * `tests/Contracts/BoundaryArchTest.php` allow-list grants this file
 * FQN the "no Laravel facades in module code" exemption alongside
 * BackfillInboxJob + IncrementalScanJob.
 */
final class DiscoveryScanJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * Locked keyword list for the broad discovery subject filter.
     * Matches CONTEXT.md D-121 verbatim. The mix of English + Dutch
     * terms reflects the user's likely receipt-sender pool (PayPal,
     * ICS Cards, Bol.com, Coolblue, Albert Heijn, etc.).
     *
     * @var list<string>
     */
    private const DISCOVERY_KEYWORDS = [
        'receipt',
        'factuur',
        'betaling',
        'invoice',
        'order',
        'bevestiging',
    ];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
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
        GraphApiClientContract $graph,
        OAuthSecretsRepository $secrets,
        KnownSenderQuery $senderQuery,
    ): void {
        // Touch $secrets so the static analyser does not flag the
        // unused argument. The OAuth clients (Gmail / Graph) load
        // credentials transparently via the repository; the contract
        // pins secrets to this job's DI surface so any future
        // re-baselining flow has it available without changing the
        // handle() signature.
        unset($secrets);

        $connection = $db->connection();

        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        // 1. Build the exclude list. Two sources of "do not surface
        //    this sender again":
        //     - every known_senders pattern (the user already promoted
        //       it, or the system seed shipped it). Some patterns are
        //       full addresses (`paypal.com`); others are @-prefixed
        //       domain suffixes (`@ics.nl`) — both forms pass through
        //       the provider's exclude operator without translation.
        //     - every discovered_senders row in state='dismissed' or
        //       'added' for this user. Both states mean "do not surface
        //       again": dismissed = user explicitly said no, added =
        //       user already promoted (and there's now a matching
        //       known_senders row, but the discovered row stays for
        //       audit).
        $knownPatterns = array_map(
            static fn ($s) => $s->emailPattern,
            $senderQuery->all($user),
        );
        $rawDismissed = $connection->table('discovered_senders')
            ->where('user_id', $this->userId)
            ->whereIn('state', ['dismissed', 'added'])
            ->pluck('sender_email')
            ->toArray();
        $dismissedSenders = [];
        foreach ($rawDismissed as $value) {
            if (is_scalar($value)) {
                $dismissedSenders[] = (string) $value;
            }
        }
        $allExcludes = array_values(array_unique(array_merge($knownPatterns, $dismissedSenders)));

        // 2. Enumerate user's connected inboxes. An empty inbox list
        //    is a no-op early exit — the user has no inboxes to scan.
        $inboxRows = $connection->table('inboxes')
            ->where('user_id', $this->userId)
            ->get(['id', 'provider']);
        if ($inboxRows->isEmpty()) {
            return;
        }

        foreach ($inboxRows as $inboxRow) {
            $rawInboxId = $inboxRow->id;
            $inboxId = is_numeric($rawInboxId) ? (int) $rawInboxId : 0;
            $rawProvider = $inboxRow->provider;
            $provider = is_string($rawProvider) ? $rawProvider : '';

            if ($inboxId <= 0 || $provider === '') {
                continue;
            }

            try {
                $this->runDiscoveryForInbox(
                    $connection,
                    $clock,
                    $gmail,
                    $graph,
                    $inboxId,
                    $provider,
                    $allExcludes,
                );
            } catch (RateLimitedException) {
                // Discovery is daily — abort silently and retry
                // tomorrow. No state machine transition because
                // discovered_senders has no per-inbox lifecycle column;
                // the absence of new rows for one day is its own
                // signal.
                return;
            } catch (Throwable) {
                // Best-effort surface — a single inbox failure must
                // not abort the per-user pass. Logging would require
                // a facade.
                continue;
            }
        }
    }

    /**
     * Walk one inbox's discovery results + upsert discovered_senders
     * rows.
     *
     * @param  list<string>  $allExcludes
     */
    private function runDiscoveryForInbox(
        Connection $connection,
        Clock $clock,
        GmailApiClientContract $gmail,
        GraphApiClientContract $graph,
        int $inboxId,
        string $provider,
        array $allExcludes,
    ): void {
        $messages = [];

        if ($provider === 'gmail') {
            $page = $gmail->listDiscoveryCandidates($inboxId, self::DISCOVERY_KEYWORDS, $allExcludes);
            foreach ($page['messages'] as $msg) {
                $messages[] = [
                    'sender_email' => strtolower($msg['fromAddress']),
                    'sender_name' => $msg['fromName'],
                    'internalDate' => $msg['internalDate'],
                ];
            }
        } elseif ($provider === 'microsoft') {
            $page = $graph->listDiscoveryCandidatesPaged($inboxId, self::DISCOVERY_KEYWORDS, $allExcludes, null);
            foreach ($page['messages'] as $rawMsg) {
                $sender = '';
                $senderName = null;
                if (isset($rawMsg['from']) && is_array($rawMsg['from'])) {
                    $emailAddress = $rawMsg['from']['emailAddress'] ?? null;
                    if (is_array($emailAddress)) {
                        $rawAddr = $emailAddress['address'] ?? null;
                        if (is_string($rawAddr)) {
                            $sender = strtolower($rawAddr);
                        }
                        $rawName = $emailAddress['name'] ?? null;
                        if (is_string($rawName) && $rawName !== '') {
                            $senderName = $rawName;
                        }
                    }
                }
                $received = $rawMsg['receivedDateTime'] ?? null;
                $internalDate = is_string($received) && $received !== '' ? $received : 'now';

                $messages[] = [
                    'sender_email' => $sender,
                    'sender_name' => $senderName,
                    'internalDate' => $internalDate,
                ];
            }
        } else {
            // Unknown provider — nothing to discover.
            return;
        }

        $lowerExcludes = array_map('strtolower', $allExcludes);

        foreach ($messages as $msg) {
            $sender = $msg['sender_email'];
            if ($sender === '') {
                continue;
            }

            // Defensive exclude — re-check the sender against the full
            // exclude list even though the provider query already
            // applied it server-side. Patterns starting with '@' match
            // by domain suffix; otherwise a substring containment match.
            $excluded = false;
            foreach ($lowerExcludes as $pattern) {
                if (str_starts_with($pattern, '@')) {
                    if (str_ends_with($sender, $pattern)) {
                        $excluded = true;
                        break;
                    }
                } else {
                    if (str_contains($sender, $pattern)) {
                        $excluded = true;
                        break;
                    }
                }
            }
            if ($excluded) {
                continue;
            }

            $internalDate = $this->safeParseDate($msg['internalDate'], $clock);
            $senderName = $msg['sender_name'];

            // Upsert discovered_senders row. UNIQUE on
            // (user_id, inbox_id, sender_email) so the existence read
            // + insert / update branch covers the same uniqueness
            // contract the schema enforces.
            $existing = $connection->table('discovered_senders')
                ->where('user_id', $this->userId)
                ->where('inbox_id', $inboxId)
                ->where('sender_email', $sender)
                ->first();

            $now = $clock->now()->toDateTimeString();
            $internalDateStr = $internalDate->format('Y-m-d H:i:s');

            if ($existing === null) {
                $connection->table('discovered_senders')->insert([
                    'user_id' => $this->userId,
                    'inbox_id' => $inboxId,
                    'sender_email' => $sender,
                    'sender_name' => $senderName,
                    'occurrence_count' => 1,
                    'last_seen_at' => $internalDateStr,
                    'state' => 'candidate',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            // Only resurface candidates — never re-touch a dismissed
            // OR added row (their `state` is terminal for the
            // discovery loop). The exclude list above SHOULD have kept
            // them out of $messages, but this is the second line of
            // defence at the persistence layer.
            $existingState = is_string($existing->state ?? null) ? $existing->state : '';
            if ($existingState !== 'candidate') {
                continue;
            }

            $existingCount = self::toInt($existing->occurrence_count ?? 0);
            $existingName = is_string($existing->sender_name ?? null) ? $existing->sender_name : null;

            $connection->table('discovered_senders')
                ->where('id', $existing->id)
                ->update([
                    'occurrence_count' => $existingCount + 1,
                    'last_seen_at' => $internalDateStr,
                    'sender_name' => $senderName ?? $existingName,
                    'updated_at' => $now,
                ]);
        }
    }

    private function safeParseDate(string $raw, Clock $clock): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return $clock->now()->toDateTimeImmutable();
        }
    }

    /**
     * Numeric coercion for raw query-builder column values. SQLite
     * returns scalars as strings via stdClass attributes; the strict-
     * rules `cast.int` lint stays happy via the is_numeric guard.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
