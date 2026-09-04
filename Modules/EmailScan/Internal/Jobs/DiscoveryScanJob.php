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
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\LockStore;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Public\Dto\KnownSenderDto;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

final class DiscoveryScanJob implements ShouldBeUnique, ShouldQueue
{
    use CoercesScalars;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public int $timeout = ScanJobBudget::TIMEOUT_SECONDS;

    /** @var list<string> */
    private const array DISCOVERY_KEYWORDS = [
        'receipt',
        'factuur',
        'betaling',
        'invoice',
        'order',
        'bevestiging',
    ];

    private const int DISCOVERY_MAX_PAGES = 10;

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
        JobUserContext $jobUser,
        LoggerInterface $logger,
    ): void {
        // Before any API client runs: they reach OAuthSecretsRepository,
        // which scopes through the guard a worker has nobody bound to.
        $jobUser->bind($this->userId);

        $connection = $db->connection();

        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        $allExcludes = $this->buildExcludeList($connection, $user, $senderQuery);

        $inboxRows = $connection->table('inboxes')
            ->where('user_id', $this->userId)
            ->get(['id', 'provider']);

        // A quota is spent per provider credential, not per pass. Returning
        // from handle() on the first throttled inbox meant one busy Gmail
        // account kept a Microsoft one from ever being walked, and an account
        // permanently over quota never reached its second inbox at all.
        $spent = [];

        foreach ($inboxRows as $inboxRow) {
            /** @var stdClass $inboxRow */
            $provider = self::toString($inboxRow->provider ?? null);

            if (isset($spent[$provider])) {
                continue;
            }

            if (! $this->scanInboxRow($inboxRow, $connection, $clock, $gmail, $graph, $allExcludes, $logger)) {
                $spent[$provider] = true;
            }
        }
    }

    // dismissed = explicit no, added = already promoted; both mean
    // "do not surface again".
    /**
     * @return list<string>
     */
    private function buildExcludeList(Connection $connection, User $user, KnownSenderQuery $senderQuery): array
    {
        $knownPatterns = array_map(
            static fn (KnownSenderDto $s): string => $s->emailPattern,
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
        LoggerInterface $logger,
    ): bool {
        $inboxId = is_numeric($inboxRow->id) ? (int) $inboxRow->id : 0;
        $provider = is_string($inboxRow->provider) ? $inboxRow->provider : '';

        if ($inboxId > 0 && $provider !== '') {
            try {
                $this->runDiscoveryForInbox($connection, $clock, $gmail, $graph, $inboxId, $provider, $allExcludes);
            } catch (RateLimitedException) {
                // discovered_senders has no per-inbox lifecycle column to
                // transition, so this line is the only record that the pass
                // stopped short of the mailbox rather than finding it empty.
                $logger->warning('DiscoveryScanJob: the provider refused on quota; its remaining inboxes wait for the next tick.', [
                    'user_id' => $this->userId,
                    'inbox_id' => $inboxId,
                    'provider' => $provider,
                ]);

                return false;
            } catch (Throwable $e) {
                $logger->warning('DiscoveryScanJob: one inbox refused its discovery pass; the rest of the account still ran.', [
                    'user_id' => $this->userId,
                    'inbox_id' => $inboxId,
                    'provider' => $provider,
                    ...SafeExceptionContext::describe($e),
                ]);
            }
        }

        return true;
    }

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

    // Narrowed at the boundary so a response-shape drift returns null
    // instead of crashing the daily scan.
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

    // Re-checked client-side even though the provider query already
    // excluded these: a query-syntax failure must not reach an upsert.
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

    // Only candidates are updated: a dismissed/added row is never
    // re-touched, the second line of defence past isExcluded.
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
        $internalDateStr = Instant::appLocal($msg['internalDate']);

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

        // Graph rejects $orderby alongside $search, so an older message can
        // arrive after a newer one. Stamping it verbatim walks last_seen_at
        // backwards, out of DiscoveredSenderQuery's window, and the sender
        // vanishes on the very pass that qualified it.
        $existingSeen = is_string($existing->last_seen_at ?? null) ? $existing->last_seen_at : '';

        $connection->table('discovered_senders')
            ->where('id', $existing->id)
            ->update([
                'occurrence_count' => $existingCount + 1,
                'last_seen_at' => max($existingSeen, $internalDateStr),
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
