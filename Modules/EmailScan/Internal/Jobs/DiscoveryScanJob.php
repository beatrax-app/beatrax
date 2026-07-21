<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class DiscoveryScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    // Locked keyword list for the broad discovery subject filter; the
    // mix of English + Dutch terms reflects the user's likely
    // receipt-sender pool (PayPal, ICS Cards, Bol.com, Coolblue, etc.).
    /** @var list<string> */
    private const DISCOVERY_KEYWORDS = [
        'receipt',
        'factuur',
        'betaling',
        'invoice',
        'order',
        'bevestiging',
    ];

    // Defensive cap on discovery-candidate pages walked per inbox per
    // day (see architecture.md for the sizing rationale and the
    // FALLBACK_WALK_HARD_CAP parallel in IncrementalScanJob).
    private const DISCOVERY_MAX_PAGES = 10;

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
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        GmailApiClientContract $gmail,
        GraphApiClientContract $graph,
        KnownSenderQuery $senderQuery,
    ): void {
        $connection = $db->connection();

        // Sets busy_timeout once for the whole handle(): the daily
        // discovery scan runs concurrently with the hourly incremental
        // scan (different ShouldBeUnique keys), and without it a
        // contended write would throw mid-loop and silently abort.
        $connection->statement('PRAGMA busy_timeout = 5000');

        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        // Builds the exclude list from known_senders patterns plus
        // dismissed/added discovered_senders rows (see architecture.md
        // for why both discovered-sender states mean "do not resurface").
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
                // tomorrow; discovered_senders has no per-inbox
                // lifecycle column to transition.
                return;
            } catch (Throwable) {
                // Best-effort surface — one inbox's failure must not
                // abort the per-user pass.
                continue;
            }
        }
    }

    // Walks one inbox's discovery results and upserts
    // discovered_senders rows.
    /**
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
            // Walks pages of discovery candidates up to the defensive
            // hard cap (see architecture.md for why a single-page call
            // previously made discovery silently fail on busy inboxes).
            $pageToken = null;
            $pagesWalked = 0;
            do {
                $page = $gmail->listDiscoveryCandidates(
                    $inboxId,
                    self::DISCOVERY_KEYWORDS,
                    $allExcludes,
                    $pageToken,
                );
                foreach ($page['messages'] as $msg) {
                    // Defensively narrows every field to DateTimeImmutable
                    // at the boundary, since the contract exposes entries
                    // as array<string, mixed> precisely so a future
                    // response-shape drift cannot crash the daily scan.
                    $rawAddr = $msg['fromAddress'] ?? null;
                    if (! is_string($rawAddr) || $rawAddr === '') {
                        continue;
                    }
                    $rawName = $msg['fromName'] ?? null;
                    $senderName = is_string($rawName) && $rawName !== '' ? $rawName : null;

                    $rawDate = $msg['internalDate'] ?? null;
                    $internalDate = is_string($rawDate) && $rawDate !== ''
                        ? $this->safeParseDate($rawDate, $clock)
                        : $clock->now()->toDateTimeImmutable();

                    $messages[] = [
                        'sender_email' => strtolower($rawAddr),
                        'sender_name' => $senderName,
                        'internalDate' => $internalDate,
                    ];
                }
                $pageToken = $page['nextPageToken'] ?? null;
                $pagesWalked++;
            } while (
                is_string($pageToken) && $pageToken !== ''
                && $pagesWalked < self::DISCOVERY_MAX_PAGES
            );
        } elseif ($provider === 'microsoft') {
            $nextLink = null;
            $pagesWalked = 0;
            do {
                $page = $graph->listDiscoveryCandidatesPaged(
                    $inboxId,
                    self::DISCOVERY_KEYWORDS,
                    $allExcludes,
                    $nextLink,
                );
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
                    // Falls back to the injected clock (not the magic
                    // 'now' string literal) when Graph omits
                    // receivedDateTime, keeping the missing-data path
                    // explicit rather than reaching for natural-language parsing.
                    $internalDate = is_string($received) && $received !== ''
                        ? $this->safeParseDate($received, $clock)
                        : $clock->now()->toDateTimeImmutable();

                    $messages[] = [
                        'sender_email' => $sender,
                        'sender_name' => $senderName,
                        'internalDate' => $internalDate,
                    ];
                }
                $nextLink = $page['nextLink'] ?? null;
                $pagesWalked++;
            } while (
                is_string($nextLink) && $nextLink !== ''
                && $pagesWalked < self::DISCOVERY_MAX_PAGES
            );
        } else {
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

            // internalDate is already a DateTimeImmutable from the
            // per-provider boundary above — no second parse needed.
            $internalDate = $msg['internalDate'];
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

            // Only resurfaces candidates — never re-touches a
            // dismissed/added row; second line of defence past the
            // exclude list above.
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

    // Numeric coercion for raw query-builder column values: SQLite
    // returns scalars as strings via stdClass attributes, so this
    // guards the int cast to keep the strict-rules cast.int lint happy.
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
