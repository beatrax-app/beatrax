<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Closure;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\InvalidStateTransitionException;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\KnownSenderDto;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackfillInboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public int $timeout = ScanJobBudget::TIMEOUT_SECONDS;

    private const int READ_QUOTA_THROTTLE_SECONDS = 2;

    // Defence in depth against a provider that answers every page with another
    // next link. The 280s job timeout bites long before this at two seconds a
    // page, so reaching the ceiling means the walk was not making progress.
    private const int MAX_WALK_PAGES = 200;

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
        // Long enough for a multi-page year-of-receipts backfill, short
        // enough that a crashed worker unblocks the next dispatch.
        return 1800;
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
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
        JobUserContext $jobUser,
        GraphDeltaWalk $deltaWalk,
        LoggerInterface $logger,
    ): void {
        $connection = $db->connection();

        $inboxRow = $connection->table('inboxes')->where('id', $this->inboxId)->first();
        if ($inboxRow === null) {
            // Inbox deleted between dispatch and worker pickup —
            // silently exit so the queue doesn't retry forever.
            return;
        }

        $rawUserId = $inboxRow->user_id;
        $userId = is_numeric($rawUserId) ? (int) $rawUserId : 0;
        $rawProvider = $inboxRow->provider;
        $provider = is_string($rawProvider) ? $rawProvider : '';

        // Before any API client runs: they reach OAuthSecretsRepository,
        // which scopes through the guard a worker has nobody bound to.
        $jobUser->bind($userId);

        /** @var User $user */
        $user = User::query()->where('id', $userId)->firstOrFail();

        $senderPatterns = array_map(
            static fn (KnownSenderDto $s): string => $s->emailPattern,
            $senderQuery->all($user),
        );

        if ($senderPatterns === []) {
            $sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::Idle->value,
                'No known senders are configured for this user.',
            );

            return;
        }

        // Defensive window clamp — the slider clamps client-side
        // but a crafted POST may carry an out-of-range value.
        $window = max(1, min(12, $this->windowMonths));

        $context = new InboxScanContext($this->inboxId, $clock, $sm, $connection, $blobStore, $mime, $userId, $logger);

        // The default arm is unreachable while the inboxes CHECK trigger
        // pair holds; it surfaces bypassed data without retrying forever.
        match ($provider) {
            MailProvider::Gmail->value => $this->runGmailBackfill($context, $gmail, $senderPatterns, $window),
            MailProvider::Microsoft->value => $this->runMicrosoftBackfill($context, $graph, $senderPatterns, $window, $deltaWalk),
            default => $sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::Error->value,
                "Unknown provider '{$provider}' — backfill cannot proceed.",
            ),
        };
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runGmailBackfill(
        InboxScanContext $context,
        GmailApiClientContract $gmail,
        array $senderPatterns,
        int $windowMonths,
    ): void {
        if (! $this->beginBackfilling($context)) {
            return;
        }

        // Gmail's after: operator bounds the q= search server-side, so the
        // walk stops at the slider value instead of the whole inbox.
        // NoOverflow, or a run on the 31st asking for one month back lands in
        // the month after the one the reader asked for and loses its receipts.
        $windowStart = $context->clock->now()->subMonthsNoOverflow($windowMonths)->toDateTimeImmutable();

        $accum = new class
        {
            public int $estimated = 0;
        };

        try {
            // Read before the walk, never after: anything that lands while the
            // walk runs then sits above the baseline and the first incremental
            // tick replays it, where a post-walk read would skip it for good.
            $baselineHistoryId = $gmail->currentHistoryId($this->inboxId);

            $this->walkAndPersist(
                $context,
                fetchNextPage: function (?string $cursor) use ($gmail, $senderPatterns, $windowStart, $accum): array {
                    $page = $gmail->listSenderMessages($this->inboxId, $senderPatterns, $cursor, $windowStart);
                    $accum->estimated = max($accum->estimated, $page['resultSizeEstimate']);

                    return [
                        'messages' => $page['messages'],
                        'nextCursor' => $page['nextPageToken'],
                        'totalEstimated' => $accum->estimated,
                        'lastMessageDate' => null,
                    ];
                },
                extractMessageId: self::extractProviderMessageId(...),
                fetchRawEml: fn (string $messageId): string => $gmail->getRawMessage($this->inboxId, $messageId),
                extractInternalDate: static fn (array $msgMeta): ?DateTimeImmutable => null,
            );

            if ($baselineHistoryId !== null && $baselineHistoryId !== '') {
                $context->sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::gmail($baselineHistoryId),
                );
            }

            $this->clearProgressAndIdle($context);
        } catch (Throwable $e) {
            $this->transitionOnScanError($context, $e);
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runMicrosoftBackfill(
        InboxScanContext $context,
        GraphApiClientContract $graph,
        array $senderPatterns,
        int $windowMonths,
        GraphDeltaWalk $deltaWalk,
    ): void {
        if (! $this->beginBackfilling($context)) {
            return;
        }

        // Anchored before any provider call so the post-walk
        // deltaPage(null, anchor) baseline cannot skip mid-walk arrivals.
        $startedAt = $context->clock->now();
        $walkStartedAt = $startedAt->toDateTimeImmutable();
        // NoOverflow, or a run on the 31st asking for one month back lands in
        // the month after the one the reader asked for and loses its receipts.
        $windowStart = $startedAt->subMonthsNoOverflow($windowMonths)->toDateTimeImmutable();

        $accum = new class
        {
            public int $estimated = 0;

            public ?string $lastMessageDate = null;
        };

        try {
            $this->walkAndPersist(
                $context,
                fetchNextPage: function (?string $cursor) use ($graph, $senderPatterns, $windowStart, $accum): array {
                    $page = $graph->listSenderMessagesPaged($this->inboxId, $senderPatterns, $windowStart, $cursor);
                    $accum->estimated = max($accum->estimated, count($page['messages']));
                    $accum->lastMessageDate = self::latestReceived($page['messages'], $accum->lastMessageDate);

                    return [
                        'messages' => $page['messages'],
                        'nextCursor' => $page['nextLink'],
                        'totalEstimated' => $accum->estimated,
                        'lastMessageDate' => $accum->lastMessageDate,
                    ];
                },
                extractMessageId: self::extractProviderMessageId(...),
                fetchRawEml: fn (string $messageId): string => $graph->getRawMessage($this->inboxId, $messageId),
                extractInternalDate: fn (array $msgMeta): ?DateTimeImmutable => $this->graphMessageInternalDate($msgMeta),
            );

            $deltaLink = $deltaWalk->collect($graph, $this->inboxId, null, $walkStartedAt)['cursor'];
            if ($deltaLink !== null && $deltaLink !== '') {
                $context->sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::microsoft($deltaLink),
                );
            }

            $this->clearProgressAndIdle($context);
        } catch (Throwable $e) {
            $this->transitionOnScanError($context, $e);
        }
    }

    // A needs_reauth inbox cannot enter backfilling, and this transition sits
    // ahead of the try that records failures — letting it out would spend the
    // whole retry budget on a condition only a Reconnect can clear.
    private function beginBackfilling(InboxScanContext $context): bool
    {
        try {
            $context->sm->applyStatus($this->inboxId, InboxScanStatus::Backfilling->value);

            return true;
        } catch (InvalidStateTransitionException $e) {
            // BackfillWindowModal answers these states in the modal rather than
            // dispatching, so arriving here is the race it cannot close — and
            // a reader whose modal closed on a backfill that never started.
            $context->logger->warning('BackfillInboxJob: the scan state machine refused the opening transition, so no backfill ran.', [
                'inbox_id' => $this->inboxId,
                'refused_transition' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  Closure(?string): array{messages: list<array<string, mixed>>, nextCursor: ?string, totalEstimated: int, lastMessageDate: ?string}  $fetchNextPage
     * @param  Closure(array<string, mixed>): string  $extractMessageId
     * @param  Closure(string): string  $fetchRawEml
     * @param  Closure(array<string, mixed>): ?DateTimeImmutable  $extractInternalDate
     */
    private function walkAndPersist(
        InboxScanContext $context,
        Closure $fetchNextPage,
        Closure $extractMessageId,
        Closure $fetchRawEml,
        Closure $extractInternalDate,
    ): void {
        [$indexed, $cursor] = $this->resumePoint($context);

        for ($walked = 0; $walked < self::MAX_WALK_PAGES; $walked++) {
            $page = $fetchNextPage($cursor);

            foreach ($page['messages'] as $msgMeta) {
                $indexed += $this->persistPageMessage($context, $msgMeta, $extractMessageId, $fetchRawEml, $extractInternalDate);
            }

            // Read before the emptiness test: both providers legitimately
            // answer an empty page while still handing back a link to the
            // next one, and stopping there drops everything past it.
            $cursor = $page['nextCursor'];

            // Routed through the state machine: BoundaryArchTest forbids any
            // other writer of inbox_scan_state, backfill_progress included.
            $context->sm->recordBackfillProgress($this->inboxId, [
                'fetched_count' => $indexed,
                'total_estimated' => $page['totalEstimated'],
                'last_message_date' => $page['lastMessageDate'],
                'page_cursor' => $cursor,
                'window_months' => $this->windowMonths,
            ]);

            if ($cursor === null || $cursor === '') {
                return;
            }

            Sleep::sleep(self::READ_QUOTA_THROTTLE_SECONDS);
        }
    }

    // A retry that restarts at page one re-walks every page the previous
    // attempt already paid for. The window is part of the key: a fresh
    // backfill over a different range must not adopt the old range's cursor.
    /**
     * @return array{0: int, 1: ?string}
     */
    private function resumePoint(InboxScanContext $context): array
    {
        $raw = $context->backfillProgress();
        if ($raw === null || ($raw['window_months'] ?? null) !== $this->windowMonths) {
            return [0, null];
        }

        $cursor = $raw['page_cursor'] ?? null;
        $indexed = $raw['fetched_count'] ?? null;

        return [
            is_int($indexed) ? $indexed : 0,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     * @param  Closure(array<string, mixed>): string  $extractMessageId
     * @param  Closure(string): string  $fetchRawEml
     * @param  Closure(array<string, mixed>): ?DateTimeImmutable  $extractInternalDate
     * @return int 1 once the message is indexed, 0 for an unusable id
     */
    private function persistPageMessage(
        InboxScanContext $context,
        array $msgMeta,
        Closure $extractMessageId,
        Closure $fetchRawEml,
        Closure $extractInternalDate,
    ): int {
        $messageId = $extractMessageId($msgMeta);
        if ($messageId === '') {
            return 0;
        }

        // A message a prior attempt already landed still counts: the progress
        // bar answers "how much of this backfill is indexed", and counting
        // inserts instead made a retry run the number backwards.
        if ($context->alreadyIndexed($messageId)) {
            return 1;
        }

        $context->storeFetchedMessage($messageId, $fetchRawEml($messageId), $extractInternalDate($msgMeta));

        return 1;
    }

    // Laravel calls this as a bare `$command->failed($e)` with no container
    // resolution, so declaring collaborators as parameters made every
    // exhausted job fatal with "Too few arguments".
    public function failed(?Throwable $exception): void
    {
        $container = Container::getInstance();
        $sm = $container->make(InboxScanStateMachine::class);
        $logger = $container->make(LoggerInterface::class);
        $reason = $exception?->getMessage() ?? 'unknown failure';

        try {
            $sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::Error->value,
                substr($reason, 0, 500),
            );
        } catch (Throwable $stateWriteFailure) {
            // An invalid transition here is fine, but a real write failure
            // (SQLITE_BUSY) strands the inbox with no UI signal.
            $logger->warning(
                'BackfillInboxJob::failed could not apply the terminal error state.',
                [
                    'inbox_id' => $this->inboxId,
                    'original_failure' => $reason,
                    'state_write_failure' => $stateWriteFailure::class,
                ],
            );
        }
    }

    private function clearProgressAndIdle(InboxScanContext $context): void
    {
        $context->sm->recordBackfillProgress($this->inboxId, null);
        $context->sm->applyStatus($this->inboxId, InboxScanStatus::Idle->value);
    }

    // Re-throwing is what hands the job back to the queue for another attempt,
    // so only a condition a later attempt could clear may leave through it.
    private function transitionOnScanError(InboxScanContext $context, Throwable $e): void
    {
        $terminal = $e instanceof InvalidGrantException || $e instanceof InboxNotConfiguredException;

        match (true) {
            $e instanceof RateLimitedException => $context->sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::RateLimited->value,
                "Retry after {$e->retryAfterSeconds}s.",
            ),
            $e instanceof InvalidGrantException => $context->sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::NeedsReauth->value,
                'OAuth grant revoked or expired.',
            ),
            $e instanceof InboxNotConfiguredException => $context->sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::NeedsReauth->value,
                'No OAuth credentials are persisted for this inbox.',
            ),
            default => $context->sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::Error->value,
                substr($e->getMessage(), 0, 500),
            ),
        };

        if (! $terminal) {
            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private static function latestReceived(array $messages, ?string $current): ?string
    {
        if ($messages === []) {
            return $current;
        }

        $lastReceived = $messages[count($messages) - 1]['receivedDateTime'] ?? null;

        return is_string($lastReceived) && $lastReceived !== '' ? $lastReceived : $current;
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     */
    private static function extractProviderMessageId(array $msgMeta): string
    {
        return is_string($msgMeta['id'] ?? null) ? $msgMeta['id'] : '';
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     */
    private function graphMessageInternalDate(array $msgMeta): ?DateTimeImmutable
    {
        $received = $msgMeta['receivedDateTime'] ?? null;
        if (! is_string($received) || $received === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($received);
        } catch (Throwable) {
            return null;
        }
    }
}
