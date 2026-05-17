<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Fixture-driven fake of the future real Gmail API client. Replays
 * canned JSON responses from
 * `Modules/EmailScan/tests/fixtures/api-responses/gmail/` so plans
 * that wire the production pipeline can drive end-to-end tests
 * without a real Google OAuth grant.
 *
 * Wave 0 ships this Fake before the production `GmailApiClient`
 * interface exists. The public method signatures here ARE the
 * contract — later plans (currently scheduled 03 + 05 + 06) ship the
 * real client and the formal interface; both must adopt the same
 * shape so the test seam stays drop-in compatible.
 */
final class FakeGmailApiClient implements GmailApiClientContract
{
    /**
     * Chronological list of calls the Fake has received.
     *
     * @var list<array{method: string, args: array<int|string, mixed>}>
     */
    private array $calls = [];

    /**
     * Inbox-id-keyed retry-after seconds when a rate-limit simulation
     * is armed. The next `listSenderMessages*` call for that inbox
     * pops the entry and throws RateLimitedException.
     *
     * @var array<int, int>
     */
    private array $rateLimitedInboxes = [];

    /**
     * Per-inbox page cursor — tracks which list page the next
     * `listSenderMessages` call should serve.
     *
     * @var array<int, int>
     */
    private array $listPageCursor = [];

    /**
     * Plan 07: inbox-id-keyed retry-after seconds for a listHistory
     * rate-limit simulation. The next `listHistory` call for that
     * inbox pops the entry and throws RateLimitedException.
     *
     * @var array<int, int>
     */
    private array $historyRateLimitedInboxes = [];

    /**
     * Plan 07: queued success-shaped listHistory response. When set,
     * the next `listHistory` call returns this payload verbatim
     * instead of replaying the 404 fixture. Set via
     * `queueHistoryResponse()`; consumed on the first call.
     *
     * @var array{history: list<array<string, mixed>>, historyId: ?string}|null
     */
    private ?array $queuedHistoryResponse = null;

    /**
     * Plan 09: queued listDiscoveryCandidates responses. Each call to
     * `listDiscoveryCandidates` shifts the front entry; once empty
     * the default three-row fixture is replayed.
     *
     * @var list<array{messages: list<array{id: string, fromAddress: string, fromName: ?string, internalDate: string}>, nextPageToken: ?string}>
     */
    private array $queuedDiscoveryResponses = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $fixtureRoot = __DIR__.'/../../tests/fixtures/api-responses/gmail',
    ) {}

    /**
     * Replays `users.messages.list` with `q=from:(...)`. On the first
     * call per inbox returns the page-1 fixture; on the second call
     * returns the empty page-2 sentinel. Subsequent calls keep
     * returning the empty sentinel.
     *
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, historyId: ?string, resultSizeEstimate: int}
     */
    public function listSenderMessages(int $inboxId, array $senderPatterns, ?string $pageToken): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'senderPatterns' => $senderPatterns,
            'pageToken' => $pageToken,
        ]];

        $this->maybeThrowRateLimit($inboxId);

        $page = $this->listPageCursor[$inboxId] ?? 0;
        $fixture = $page === 0
            ? 'messages-list-page-1.json'
            : 'messages-list-page-2-empty.json';
        $this->listPageCursor[$inboxId] = $page + 1;

        $payload = $this->readJson($fixture);
        /** @var list<array{id: string, threadId: string}> $messages */
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $nextPageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        $estimate = isset($payload['resultSizeEstimate']) && is_int($payload['resultSizeEstimate'])
            ? $payload['resultSizeEstimate']
            : 0;

        return [
            'messages' => $messages,
            'nextPageToken' => $nextPageToken,
            'historyId' => '12345',
            'resultSizeEstimate' => $estimate,
        ];
    }

    /**
     * Replays `users.messages.get?format=raw`. Returns the
     * base64url-decoded RFC 822 byte stream so the caller sees the
     * same payload shape the real client would surface after
     * unwrapping Gmail's transport encoding.
     */
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'providerMessageId' => $providerMessageId,
        ]];

        $fixture = 'messages-get-raw-'.$this->fixtureSlug($providerMessageId).'.json';
        $payload = $this->readJson($fixture);

        $raw = is_string($payload['raw'] ?? null) ? $payload['raw'] : null;
        if ($raw === null) {
            throw new RuntimeException(
                "Fake Gmail fixture {$fixture} has no `raw` field for message {$providerMessageId}.",
            );
        }

        return self::base64UrlDecode($raw);
    }

    /**
     * Replays `users.history.list`.
     *
     * Default behaviour: replays the Wave 0 404 fixture, throwing
     * CursorExpiredException so the caller exercises the fallback
     * path. This keeps Plan 05 / 06 tests that never call the
     * queue / simulate helpers unchanged.
     *
     * Test-controllable shapes (Plan 07):
     *  - `queueHistoryResponse($messageIds, $newHistoryId)` queues a
     *    success response with those message-ids returned in the
     *    history array (via the messagesAdded shape) plus the new
     *    historyId.
     *  - `simulateHistoryRateLimit($inboxId, $retryAfterSeconds)`
     *    arms a 429 on the next listHistory call.
     *
     * @return array{history: list<array<string, mixed>>, historyId: ?string}
     */
    public function listHistory(int $inboxId, string $startHistoryId): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'startHistoryId' => $startHistoryId,
        ]];

        if (array_key_exists($inboxId, $this->historyRateLimitedInboxes)) {
            $retryAfter = $this->historyRateLimitedInboxes[$inboxId];
            unset($this->historyRateLimitedInboxes[$inboxId]);
            $payload = $this->readJson('rate-limit-403.json');
            $error = $payload['error'] ?? null;
            $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Gmail rate limit exceeded.';
            throw new RateLimitedException($retryAfter, $message);
        }

        if ($this->queuedHistoryResponse !== null) {
            $queued = $this->queuedHistoryResponse;
            $this->queuedHistoryResponse = null;

            return $queued;
        }

        $payload = $this->readJson('history-list-404.json');
        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            if (is_int($code) && $code === 404) {
                $message = $error['message'] ?? '';
                throw CursorExpiredException::gmail(is_string($message) ? $message : '');
            }
        }

        return [
            'history' => [],
            'historyId' => null,
        ];
    }

    /**
     * Plan 09: replays the discovery query as the production wrapper
     * would surface it — one entry per message carrying the parsed
     * sender address + name + internalDate (NOT the raw .eml body —
     * discovery never persists blobs).
     *
     * Test-controllable shape:
     *  - `queueDiscoveryResponse([...])` queues a specific list of
     *    sender records for the next call. The next call shifts the
     *    queue front; subsequent calls return the default seeded
     *    candidates (the three known-sender fixture rows from
     *    messages-list-page-1.json).
     *  - `simulateRateLimit($inboxId, $retryAfter)` arms the listDiscovery
     *    surface via the shared rate-limit pool (same as
     *    listSenderMessages).
     *
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array{id: string, fromAddress: string, fromName: ?string, internalDate: string}>, nextPageToken: ?string}
     */
    public function listDiscoveryCandidates(int $inboxId, array $keywords, array $excludeSenders): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'keywords' => $keywords,
            'excludeSenders' => $excludeSenders,
        ]];

        $this->maybeThrowRateLimit($inboxId);

        if ($this->queuedDiscoveryResponses !== []) {
            return array_shift($this->queuedDiscoveryResponses);
        }

        // Default fixture replay — synthesise the three Wave 0 sender
        // metadata rows so any pre-existing test calling
        // listDiscoveryCandidates without queueing keeps seeing the
        // same shape (with the new richer fields). Coordinates land
        // on the same dates the .eml fixtures use so other callers can
        // cross-reference.
        return [
            'messages' => [
                ['id' => 'paypal-sample-receipt', 'fromAddress' => 'service@paypal.com', 'fromName' => 'PayPal', 'internalDate' => '2026-05-11T09:14:21Z'],
                ['id' => 'ics-sample-statement-notice', 'fromAddress' => 'noreply@ics.nl', 'fromName' => 'ICS Cards', 'internalDate' => '2026-05-12T06:00:13Z'],
                ['id' => 'googleplay-sample-purchase', 'fromAddress' => 'googleplay-noreply@google.com', 'fromName' => 'Google Play', 'internalDate' => '2026-05-13T17:45:49Z'],
            ],
            'nextPageToken' => null,
        ];
    }

    /**
     * Plan 09: queue the next listDiscoveryCandidates response. Each
     * queued entry is consumed (FIFO) by the next call; once the queue
     * empties, calls fall back to the default three-row fixture.
     *
     * @param  list<array{id: string, fromAddress: string, fromName: ?string, internalDate: string}>  $messages
     */
    public function queueDiscoveryResponse(array $messages, ?string $nextPageToken = null): void
    {
        $this->queuedDiscoveryResponses[] = [
            'messages' => $messages,
            'nextPageToken' => $nextPageToken,
        ];
    }

    /**
     * Arms a rate-limit simulation. The next `listSenderMessages` or
     * `listDiscoveryCandidates` call for the given inbox pops the
     * armed entry and throws RateLimitedException with the configured
     * retry-after.
     */
    public function simulateRateLimit(int $inboxId, int $retryAfterSeconds = 2): void
    {
        $this->rateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    /**
     * Plan 07: arm a rate-limit response for the next listHistory
     * call. IncrementalScanJob calls listHistory before any
     * listSenderMessages call, so a Gmail incremental-scan rate-limit
     * fires via this surface (not via simulateRateLimit, which
     * targets listSenderMessages).
     */
    public function simulateHistoryRateLimit(int $inboxId, int $retryAfterSeconds = 60): void
    {
        $this->historyRateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    /**
     * Plan 07: queue the next listHistory response so the caller
     * sees a success shape instead of the default 404 fixture. The
     * messages-added list is materialised from `$messageIds` so the
     * caller's downstream getRawMessage walk has fixture-mapped
     * blobs to fetch.
     *
     * @param  list<string>  $messageIds
     */
    public function queueHistoryResponse(array $messageIds, ?string $newHistoryId): void
    {
        $history = [];
        foreach ($messageIds as $id) {
            $history[] = [
                'id' => 'history-entry-'.$id,
                'messagesAdded' => [
                    ['message' => ['id' => $id, 'threadId' => 'thread-'.$id]],
                ],
            ];
        }

        $this->queuedHistoryResponse = [
            'history' => $history,
            'historyId' => $newHistoryId,
        ];
    }

    /**
     * @return list<array{method: string, args: array<int|string, mixed>}>
     */
    public function getRequestedCalls(): array
    {
        return $this->calls;
    }

    private function maybeThrowRateLimit(int $inboxId): void
    {
        if (! array_key_exists($inboxId, $this->rateLimitedInboxes)) {
            return;
        }
        $retryAfter = $this->rateLimitedInboxes[$inboxId];
        unset($this->rateLimitedInboxes[$inboxId]);
        $payload = $this->readJson('rate-limit-403.json');
        $error = $payload['error'] ?? null;
        $message = is_array($error) && isset($error['message']) && is_string($error['message'])
            ? $error['message']
            : 'Gmail rate limit exceeded.';
        throw new RateLimitedException($retryAfter, $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $fileName): array
    {
        $path = $this->fixtureRoot.'/'.$fileName;
        $bytes = $this->files->get($path);

        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Map an opaque provider message-id to the fixture slug used in
     * the `messages-get-raw-*.json` filename. The synthesised corpus
     * stores ids in the form `paypal-sample-receipt`, etc.; the
     * fixture slug is the first dash-segment.
     */
    private function fixtureSlug(string $providerMessageId): string
    {
        $dash = strpos($providerMessageId, '-');
        if ($dash === false) {
            return $providerMessageId;
        }

        return substr($providerMessageId, 0, $dash);
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = $value.str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url payload in Gmail fixture.');
        }

        return $decoded;
    }
}
