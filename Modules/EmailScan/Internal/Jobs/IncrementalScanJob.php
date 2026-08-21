<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Internal\Clients\CursorExpiredException;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\InvalidStateTransitionException;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Throwable;

final class IncrementalScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public int $timeout = ScanJobBudget::TIMEOUT_SECONDS;

    // Defence-in-depth ceiling: the 7-day window is the primary guard,
    // this stops a provider paginating forever from exhausting the heap.
    private const FALLBACK_WALK_HARD_CAP = 500;

    // Caps the cursor-expiry re-scan at 7 days back from last_scan_at; the
    // rest is recovered on the next Reconnect or manual backfill.
    private const FALLBACK_WALK_DAYS = 7;

    public function __construct(public readonly int $inboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function uniqueFor(): int
    {
        // Incremental scans finish in seconds; this only bounds a lock
        // left behind by a crashed worker.
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
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
        ScanMessageMapper $mapper,
        JobUserContext $jobUser,
    ): void {
        $prepared = $this->prepareScan($db, $clock, $blobStore, $mime, $sm, $senderQuery);
        if ($prepared === null) {
            return;
        }

        // Must precede every API client: they reach OAuthSecretsRepository,
        // which scopes through the guard.
        $jobUser->bind($prepared->context->userId());

        try {
            // The default arm is unreachable while the inboxes CHECK trigger
            // pair holds; it surfaces bypassed data without retrying forever.
            match ($prepared->provider) {
                MailProvider::Gmail->value => $this->runGmailIncremental($prepared->context, $gmail, $prepared->senderPatterns, $prepared->stateRow, $mapper),
                MailProvider::Microsoft->value => $this->runMicrosoftIncremental($prepared->context, $graph, $prepared->senderPatterns, $prepared->stateRow, $mapper),
                default => $sm->applyStatus(
                    $this->inboxId,
                    InboxScanStatus::Error->value,
                    "Unknown provider '{$prepared->provider}' — incremental scan cannot proceed.",
                ),
            };

            if ($prepared->provider === MailProvider::Gmail->value || $prepared->provider === MailProvider::Microsoft->value) {
                $sm->applyStatus($this->inboxId, InboxScanStatus::Idle->value);
                $sm->resetRetryAttempts($this->inboxId);
            }
        } catch (Throwable $e) {
            $this->transitionOnScanError($sm, $e);
        }
    }

    private function prepareScan(
        DatabaseManager $db,
        Clock $clock,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
    ): ?PreparedScan {
        $connection = $db->connection();

        $rows = $this->loadScannableRows($connection);
        if ($rows === null) {
            return null;
        }
        [$inboxRow, $stateRow] = $rows;

        $rawUserId = $inboxRow->user_id;
        $userId = is_numeric($rawUserId) ? (int) $rawUserId : 0;
        $rawProvider = $inboxRow->provider;
        $provider = is_string($rawProvider) ? $rawProvider : '';

        $senderPatterns = $this->senderPatternsFor($userId, $senderQuery);
        if ($senderPatterns === [] || ! $this->beginScanning($sm)) {
            return null;
        }

        $context = new InboxScanContext($this->inboxId, $clock, $sm, $connection, $blobStore, $mime, $userId);

        return new PreparedScan($context, $provider, $senderPatterns, $stateRow);
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass}|null
     */
    private function loadScannableRows(Connection $connection): ?array
    {
        $inboxRow = $connection->table('inboxes')->where('id', $this->inboxId)->first();
        $stateRow = $connection->table('inbox_scan_state')
            ->where('inbox_id', $this->inboxId)
            ->where('folder', 'INBOX')
            ->first();
        if ($inboxRow === null || $stateRow === null) {
            // Inbox deleted between dispatch and pickup, or no scan-state row
            // yet — exit silently so the queue doesn't retry forever.
            return null;
        }

        // needs_reauth is terminal until the user hits Reconnect: another
        // scan attempt cannot repair a revoked refresh token.
        $currentStatus = is_string($stateRow->status) ? $stateRow->status : '';
        if ($currentStatus === InboxScanStatus::NeedsReauth->value) {
            return null;
        }

        return [$inboxRow, $stateRow];
    }

    /**
     * @return list<string>
     */
    private function senderPatternsFor(int $userId, KnownSenderQuery $senderQuery): array
    {
        /** @var User $user */
        $user = User::query()->where('id', $userId)->firstOrFail();

        return array_map(
            static fn ($s) => $s->emailPattern,
            $senderQuery->all($user),
        );
    }

    private function beginScanning(InboxScanStateMachine $sm): bool
    {
        // A backfill mid-flight makes backfilling -> scanning illegal, and a
        // rejected transition there means skip rather than error.
        try {
            $sm->applyStatus($this->inboxId, InboxScanStatus::Scanning->value);

            return true;
        } catch (InvalidStateTransitionException) {
            return false;
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runGmailIncremental(
        InboxScanContext $context,
        GmailApiClientContract $gmail,
        array $senderPatterns,
        \stdClass $stateRow,
        ScanMessageMapper $mapper,
    ): void {
        $startHistoryId = is_string($stateRow->last_history_id ?? null) ? $stateRow->last_history_id : '';
        if ($startHistoryId === '') {
            // An inbox backfilled before the baseline existed carries no cursor
            // and would sit idle forever. Adopting the mailbox's current one
            // makes the next tick a real delta; there is none to compute now.
            $baseline = $gmail->currentHistoryId($this->inboxId);
            if ($baseline !== null && $baseline !== '') {
                $context->sm->recordCursor($this->inboxId, ScanCursor::gmail($baseline));
            }

            return;
        }

        [$messageIds, $newHistoryId] = $this->fetchGmailHistory($gmail, $context->clock, $stateRow, $senderPatterns, $startHistoryId, $mapper);

        foreach ($messageIds as $messageId) {
            if (! $context->alreadyIndexed($messageId)) {
                $context->storeFetchedMessage($messageId, $gmail->getRawMessage($this->inboxId, $messageId), null);
            }
        }

        if (is_string($newHistoryId) && $newHistoryId !== '') {
            $context->sm->recordCursor($this->inboxId, ScanCursor::gmail($newHistoryId));
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     * @return array{0: list<string>, 1: ?string}
     */
    private function fetchGmailHistory(
        GmailApiClientContract $gmail,
        Clock $clock,
        \stdClass $stateRow,
        array $senderPatterns,
        string $startHistoryId,
        ScanMessageMapper $mapper,
    ): array {
        try {
            $history = $gmail->listHistory($this->inboxId, $startHistoryId);

            return [$mapper->extractGmailHistoryMessageIds($history['history']), $history['historyId']];
        } catch (CursorExpiredException) {
            // listSenderMessages returns no historyId, so the prior cursor is
            // kept and the next run re-attempts listHistory.
            return [$this->gmailFallbackWalk($gmail, $stateRow, $senderPatterns, $clock, $mapper), null];
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runMicrosoftIncremental(
        InboxScanContext $context,
        GraphApiClientContract $graph,
        array $senderPatterns,
        \stdClass $stateRow,
        ScanMessageMapper $mapper,
    ): void {
        $storedDeltaLink = is_string($stateRow->last_delta_link ?? null) ? $stateRow->last_delta_link : '';
        if ($storedDeltaLink === '') {
            return;
        }

        [$messages, $newDeltaLink] = $this->fetchGraphDelta($graph, $context->clock, $stateRow, $senderPatterns, $storedDeltaLink, $mapper);

        foreach ($messages as $msgMeta) {
            $this->persistDeltaMessage($context, $graph, $msgMeta, $senderPatterns, $mapper);
        }

        if (is_string($newDeltaLink) && $newDeltaLink !== '') {
            $context->sm->recordCursor($this->inboxId, ScanCursor::microsoft($newDeltaLink));
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function fetchGraphDelta(
        GraphApiClientContract $graph,
        Clock $clock,
        \stdClass $stateRow,
        array $senderPatterns,
        string $storedDeltaLink,
        ScanMessageMapper $mapper,
    ): array {
        try {
            $page = $graph->deltaPage($this->inboxId, $storedDeltaLink);

            return [$page['messages'], $page['deltaLink']];
        } catch (CursorExpiredException) {
            // Re-baselining from the pre-walk timestamp closes the gap-window
            // race; BackfillInboxJob anchors the same way.
            $walkStartedAt = $clock->now()->toDateTimeImmutable();
            $messages = $this->graphFallbackWalk($graph, $stateRow, $senderPatterns, $clock, $mapper);
            $baseline = $graph->deltaPage($this->inboxId, null, $walkStartedAt);

            return [$messages, $baseline['deltaLink']];
        }
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     * @param  list<string>  $senderPatterns
     */
    private function persistDeltaMessage(
        InboxScanContext $context,
        GraphApiClientContract $graph,
        array $msgMeta,
        array $senderPatterns,
        ScanMessageMapper $mapper,
    ): void {
        // Graph's delta endpoint ignores a from-address filter, so the
        // allow-list has to be applied client-side here.
        $senderAddr = $mapper->extractSenderAddress($msgMeta);
        if (! $mapper->matchesAnyPattern($senderAddr, $senderPatterns)) {
            return;
        }

        $messageId = $mapper->extractProviderMessageId($msgMeta);
        if ($messageId === '' || $context->alreadyIndexed($messageId)) {
            return;
        }

        $context->storeFetchedMessage(
            $messageId,
            $graph->getRawMessage($this->inboxId, $messageId),
            $mapper->graphMessageInternalDate($msgMeta),
        );
    }

    // Laravel calls this as a bare `$command->failed($e)` with no container
    // resolution, so declaring collaborators as parameters made every
    // exhausted job fatal with "Too few arguments".
    public function failed(?Throwable $exception): void
    {
        $sm = Container::getInstance()->make(InboxScanStateMachine::class);

        try {
            $sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::Error->value,
                substr($exception?->getMessage() ?? 'unknown failure', 0, 500),
            );
        } catch (Throwable) {
            // An invalid transition here must not escalate into a hard
            // queue-worker error.
        }
    }

    private function transitionOnScanError(InboxScanStateMachine $sm, Throwable $e): void
    {
        match (true) {
            $e instanceof RateLimitedException => $sm->applyRateLimited($this->inboxId, $e->retryAfterSeconds),
            $e instanceof InvalidGrantException => $sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::NeedsReauth->value,
                'OAuth grant revoked or expired.',
            ),
            default => $sm->applyStatus(
                $this->inboxId,
                InboxScanStatus::Error->value,
                substr($e->getMessage(), 0, 500),
            ),
        };

        if (! $e instanceof InvalidGrantException) {
            throw $e;
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     * @return list<string>
     */
    private function gmailFallbackWalk(
        GmailApiClientContract $gmail,
        \stdClass $stateRow,
        array $senderPatterns,
        Clock $clock,
        ScanMessageMapper $mapper,
    ): array {
        $lastScanAt = is_string($stateRow->last_scan_at ?? null) && $stateRow->last_scan_at !== ''
            ? $mapper->safeParseDate($stateRow->last_scan_at)
            : $clock->now()->toDateTimeImmutable();
        $windowStart = $lastScanAt->modify('-'.self::FALLBACK_WALK_DAYS.' days');

        $ids = [];
        $pageToken = null;
        do {
            $page = $gmail->listSenderMessages($this->inboxId, $senderPatterns, $pageToken, $windowStart);
            foreach ($page['messages'] as $msg) {
                $id = $msg['id'];
                if ($id === '') {
                    continue;
                }
                $ids[] = $id;
                if (count($ids) >= self::FALLBACK_WALK_HARD_CAP) {
                    return $ids;
                }
            }
            $pageToken = $page['nextPageToken'];
        } while ($pageToken !== null && $pageToken !== '');

        return $ids;
    }

    /**
     * @param  list<string>  $senderPatterns
     * @return list<array<string, mixed>>
     */
    private function graphFallbackWalk(
        GraphApiClientContract $graph,
        \stdClass $stateRow,
        array $senderPatterns,
        Clock $clock,
        ScanMessageMapper $mapper,
    ): array {
        $lastScanAt = is_string($stateRow->last_scan_at ?? null) && $stateRow->last_scan_at !== ''
            ? $mapper->safeParseDate($stateRow->last_scan_at)
            : $clock->now()->toDateTimeImmutable();
        $windowStart = $lastScanAt->modify('-'.self::FALLBACK_WALK_DAYS.' days');

        $results = [];
        $nextLink = null;
        do {
            $page = $graph->listSenderMessagesPaged($this->inboxId, $senderPatterns, $windowStart, $nextLink);
            foreach ($page['messages'] as $msg) {
                $results[] = $msg;
                if (count($results) >= self::FALLBACK_WALK_HARD_CAP) {
                    return $results;
                }
            }
            $nextLink = $page['nextLink'];
        } while ($nextLink !== null && $nextLink !== '');

        return $results;
    }
}
