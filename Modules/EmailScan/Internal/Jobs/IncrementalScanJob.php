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

    // Defensive cap on the cursor-expiry fallback walk so a
    // misbehaving provider returning pages indefinitely cannot
    // exhaust the heap; the 7-day window is the primary guard, this
    // is the defence-in-depth ceiling.
    private const FALLBACK_WALK_HARD_CAP = 500;

    // Cursor-expiry fallback window: caps the re-scan at 7 days back
    // from last_scan_at; the rest of the retention window is
    // recovered on the next Reconnect or manual backfill.
    private const FALLBACK_WALK_DAYS = 7;

    public function __construct(public readonly int $inboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function uniqueFor(): int
    {
        // 10-minute single-flight ceiling: incremental scans complete
        // in seconds, so the lock shouldn't linger past a crash.
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

        // Before any API client runs: everything below reaches
        // OAuthSecretsRepository, which scopes through the guard.
        $jobUser->bind($prepared->context->userId());

        try {
            // The default arm surfaces a misconfigured provider without
            // retrying forever; the inboxes CHECK trigger pair keeps
            // gmail|microsoft the only legal production values, so it is
            // reached only if data bypassed the migration.
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

    // Folds the inbox-missing, state-missing, needs-reauth, no-sender
    // and reject-transition preconditions into one nullable return so
    // handle() dispatches on a ready scan without any early-exit noise.
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
            // Inbox deleted between dispatch and worker pickup, or no
            // scan-state row exists yet — silently exit so the queue
            // doesn't retry forever.
            return null;
        }

        // needs_reauth inboxes are terminal until the user hits
        // Reconnect: no provider API call, since a revoked refresh
        // token cannot be repaired by another scan attempt.
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
        // Collapses the contention case where a backfill is mid-flight:
        // the state machine rejects backfilling -> scanning, so a
        // rejected transition means skip rather than error.
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
            // No cursor yet — the next hour's tick after a backfill
            // populates last_history_id will pick up the deltas.
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
            // listSenderMessages never returns a historyId (Gmail
            // surfaces it via getProfile in production), so the prior
            // cursor is kept — the next run re-attempts listHistory.
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
            // Fallback walk anchored to the pre-walk timestamp, then
            // re-baselined via deltaPage(null, walkStartedAt) — the same
            // pattern BackfillInboxJob uses to close the gap-window race.
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
        // Client-side post-filter: Graph's delta endpoint doesn't honour
        // a from-address filter, so senders outside the allow-list are
        // dropped here (the fallback walk already filters server-side
        // via listSenderMessagesPaged).
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

    // Laravel calls this as a bare `$command->failed($e)` — one argument, NO
    // container resolution (unlike handle()). Declaring collaborators as
    // parameters made every exhausted job fatal with "Too few arguments", so
    // the terminal error transition this hook exists to apply never ran.
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
            // Invalid transitions are acceptable on this hook — they
            // must not escalate into a hard queue-worker error.
        }
    }

    // Rate-limit bumps retry_attempts + stamps the retry hint and
    // rethrows for the backoff curve, a revoked grant parks the inbox
    // at needs_reauth without a rethrow, and any other throwable records
    // the error before rethrowing to the failed hook.
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

    // Date-bounded fallback walk over Gmail's listSenderMessages,
    // invoked when listHistory's startHistoryId has aged out; passes
    // $windowStart = last_scan_at - FALLBACK_WALK_DAYS so the
    // server-side after: filter trims the result set to the recovery window.
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

    // Date-bounded fallback walk over Microsoft Graph's
    // listSenderMessagesPaged, invoked when deltaPage's stored
    // delta-link has aged out; walks at most FALLBACK_WALK_HARD_CAP
    // messages defensively.
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
