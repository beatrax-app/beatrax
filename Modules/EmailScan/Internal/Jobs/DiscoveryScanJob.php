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
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use stdClass;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class DiscoveryScanJob implements ShouldBeUnique, ShouldQueue
{
    use CoercesScalars;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

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

        $allExcludes = $this->buildExcludeList($connection, $user, $senderQuery);

        $inboxRows = $connection->table('inboxes')
            ->where('user_id', $this->userId)
            ->get(['id', 'provider']);

        foreach ($inboxRows as $inboxRow) {
            /** @var stdClass $inboxRow */
            if (! $this->scanInboxRow($inboxRow, $connection, $clock, $gmail, $graph, $allExcludes)) {
                return;
            }
        }
    }

    // Builds the exclude list from known_senders patterns plus
    // dismissed/added discovered_senders rows (see architecture.md for
    // why both discovered-sender states mean "do not resurface").
    /**
     * @return list<string>
     */
    private function buildExcludeList(Connection $connection, User $user, KnownSenderQuery $senderQuery): array
    {
        $knownPatterns = array_map(
            static fn ($s) => $s->emailPattern,
            $senderQuery->all($user),
        );
        $rawDismissed = $connection->table('discovered_senders')
            ->where('user_id', $this->userId)
            ->whereIn('state', [DiscoveredSenderState::Dismissed->value, DiscoveredSenderState::Added->value])
            ->pluck('sender_email')
            ->toArray();
        $dismissedSenders = [];
        foreach ($rawDismissed as $value) {
            if (is_scalar($value)) {
                $dismissedSenders[] = (string) $value;
            }
        }

        return array_values(array_unique(array_merge($knownPatterns, $dismissedSenders)));
    }

    // Runs discovery for one inbox row, swallowing per-inbox failures so
    // one bad inbox never aborts the pass. Returns false only on a rate
    // limit, which signals handle() to stop the whole daily sweep.
    /**
     * @param  list<string>  $allExcludes
     */
    private function scanInboxRow(
        stdClass $inboxRow,
        Connection $connection,
        Clock $clock,
        GmailApiClientContract $gmail,
        GraphApiClientContract $graph,
        array $allExcludes,
    ): bool {
        $inboxId = is_numeric($inboxRow->id) ? (int) $inboxRow->id : 0;
        $provider = is_string($inboxRow->provider) ? $inboxRow->provider : '';

        if ($inboxId > 0 && $provider !== '') {
            try {
                $this->runDiscoveryForInbox($connection, $clock, $gmail, $graph, $inboxId, $provider, $allExcludes);
            } catch (RateLimitedException) {
                // Discovery is daily — abort silently and retry
                // tomorrow; discovered_senders has no per-inbox
                // lifecycle column to transition.
                return false;
            } catch (Throwable) {
                // Best-effort surface — one inbox's failure must not
                // abort the per-user pass.
            }
        }

        return true;
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
        $messages = match ($provider) {
            MailProvider::Gmail->value => $this->collectGmailMessages($gmail, $clock, $inboxId, $allExcludes),
            MailProvider::Microsoft->value => $this->collectMicrosoftMessages($graph, $clock, $inboxId, $allExcludes),
            default => null,
        };
        if ($messages === null) {
            return;
        }

        $lowerExcludes = array_map('strtolower', $allExcludes);

        foreach ($messages as $msg) {
            $sender = $msg['sender_email'];
            if ($sender === '' || $this->isExcluded($sender, $lowerExcludes)) {
                continue;
            }

            $this->upsertDiscoveredSender($connection, $clock, $inboxId, $msg);
        }
    }

    // Walks pages of Gmail discovery candidates up to the defensive hard
    // cap (see architecture.md for why a single-page call previously
    // made discovery silently fail on busy inboxes).
    /**
     * @param  list<string>  $allExcludes
     * @return list<array{sender_email: string, sender_name: ?string, internalDate: DateTimeImmutable}>
     */
    private function collectGmailMessages(GmailApiClientContract $gmail, Clock $clock, int $inboxId, array $allExcludes): array
    {
        $messages = [];
        $pageToken = null;
        $pagesWalked = 0;
        do {
            $page = $gmail->listDiscoveryCandidates($inboxId, self::DISCOVERY_KEYWORDS, $allExcludes, $pageToken);
            foreach ($page['messages'] as $msg) {
                $mapped = $this->mapGmailMessage($msg, $clock);
                if ($mapped !== null) {
                    $messages[] = $mapped;
                }
            }
            $pageToken = $page['nextPageToken'] ?? null;
            $pagesWalked++;
        } while (
            is_string($pageToken) && $pageToken !== ''
            && $pagesWalked < self::DISCOVERY_MAX_PAGES
        );

        return $messages;
    }

    // Defensively narrows every field at the boundary, since the
    // contract exposes entries as array<string, mixed> precisely so a
    // future response-shape drift cannot crash the daily scan.
    /**
     * @param  array<string, mixed>  $msg
     * @return array{sender_email: string, sender_name: ?string, internalDate: DateTimeImmutable}|null
     */
    private function mapGmailMessage(array $msg, Clock $clock): ?array
    {
        $rawAddr = $msg['fromAddress'] ?? null;
        if (! is_string($rawAddr) || $rawAddr === '') {
            return null;
        }
        $rawName = $msg['fromName'] ?? null;
        $senderName = is_string($rawName) && $rawName !== '' ? $rawName : null;

        $rawDate = $msg['internalDate'] ?? null;
        $internalDate = is_string($rawDate) && $rawDate !== ''
            ? $this->safeParseDate($rawDate, $clock)
            : $clock->now()->toDateTimeImmutable();

        return [
            'sender_email' => strtolower($rawAddr),
            'sender_name' => $senderName,
            'internalDate' => $internalDate,
        ];
    }

    /**
     * @param  list<string>  $allExcludes
     * @return list<array{sender_email: string, sender_name: ?string, internalDate: DateTimeImmutable}>
     */
    private function collectMicrosoftMessages(GraphApiClientContract $graph, Clock $clock, int $inboxId, array $allExcludes): array
    {
        $messages = [];
        $nextLink = null;
        $pagesWalked = 0;
        do {
            $page = $graph->listDiscoveryCandidatesPaged($inboxId, self::DISCOVERY_KEYWORDS, $allExcludes, $nextLink);
            foreach ($page['messages'] as $rawMsg) {
                $messages[] = $this->mapMicrosoftMessage($rawMsg, $clock);
            }
            $nextLink = $page['nextLink'] ?? null;
            $pagesWalked++;
        } while (
            is_string($nextLink) && $nextLink !== ''
            && $pagesWalked < self::DISCOVERY_MAX_PAGES
        );

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $rawMsg
     * @return array{sender_email: string, sender_name: ?string, internalDate: DateTimeImmutable}
     */
    private function mapMicrosoftMessage(array $rawMsg, Clock $clock): array
    {
        [$sender, $senderName] = $this->extractMicrosoftSender($rawMsg);

        // Falls back to the injected clock (not the magic 'now' string
        // literal) when Graph omits receivedDateTime, keeping the
        // missing-data path explicit rather than reaching for
        // natural-language parsing.
        $received = $rawMsg['receivedDateTime'] ?? null;
        $internalDate = is_string($received) && $received !== ''
            ? $this->safeParseDate($received, $clock)
            : $clock->now()->toDateTimeImmutable();

        return [
            'sender_email' => $sender,
            'sender_name' => $senderName,
            'internalDate' => $internalDate,
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMsg
     * @return array{0: string, 1: ?string}
     */
    private function extractMicrosoftSender(array $rawMsg): array
    {
        $from = $rawMsg['from'] ?? null;
        $emailAddress = is_array($from) ? ($from['emailAddress'] ?? null) : null;
        if (! is_array($emailAddress)) {
            return ['', null];
        }

        $rawAddr = $emailAddress['address'] ?? null;
        $sender = is_string($rawAddr) ? strtolower($rawAddr) : '';

        $rawName = $emailAddress['name'] ?? null;
        $senderName = is_string($rawName) && $rawName !== '' ? $rawName : null;

        return [$sender, $senderName];
    }

    // Defensive exclude — re-check the sender against the full exclude
    // list even though the provider query already applied it
    // server-side. Patterns starting with '@' match by domain suffix;
    // otherwise a substring containment match.
    /**
     * @param  list<string>  $lowerExcludes
     */
    private function isExcluded(string $sender, array $lowerExcludes): bool
    {
        foreach ($lowerExcludes as $pattern) {
            $hit = str_starts_with($pattern, '@')
                ? str_ends_with($sender, $pattern)
                : str_contains($sender, $pattern);
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    // Upsert on UNIQUE (user_id, inbox_id, sender_email): the existence
    // read + insert / update branch mirrors the schema's uniqueness
    // contract. Only candidates are resurfaced — a dismissed/added row
    // is never re-touched (second line of defence past isExcluded).
    /**
     * @param  array{sender_email: string, sender_name: ?string, internalDate: DateTimeImmutable}  $msg
     */
    private function upsertDiscoveredSender(Connection $connection, Clock $clock, int $inboxId, array $msg): void
    {
        $sender = $msg['sender_email'];
        $senderName = $msg['sender_name'];
        $existing = $connection->table('discovered_senders')
            ->where('user_id', $this->userId)
            ->where('inbox_id', $inboxId)
            ->where('sender_email', $sender)
            ->first();

        $now = $clock->now()->toDateTimeString();
        $internalDateStr = $msg['internalDate']->format('Y-m-d H:i:s');

        if ($existing === null) {
            $connection->table('discovered_senders')->insert([
                'user_id' => $this->userId,
                'inbox_id' => $inboxId,
                'sender_email' => $sender,
                'sender_name' => $senderName,
                'occurrence_count' => 1,
                'last_seen_at' => $internalDateStr,
                'state' => DiscoveredSenderState::Candidate->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $existingState = is_string($existing->state ?? null) ? $existing->state : '';
        if ($existingState !== DiscoveredSenderState::Candidate->value) {
            return;
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

    private function safeParseDate(string $raw, Clock $clock): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return $clock->now()->toDateTimeImmutable();
        }
    }
}
