<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;

final class FakeGmailApiClient implements GmailApiClientContract
{
    /** @var list<array{method: string, args: array<int|string, mixed>}> */
    private array $calls = [];

    // Inbox-id-keyed retry-after seconds when a rate-limit simulation
    // is armed; the next listSenderMessages* call for that inbox pops
    // the entry and throws RateLimitedException.
    /** @var array<int, int> */
    private array $rateLimitedInboxes = [];

    // Per-inbox page cursor: tracks which list page the next
    // listSenderMessages call should serve.
    /** @var array<int, int> */
    private array $listPageCursor = [];

    // Inbox-id-keyed retry-after seconds for a listHistory rate-limit
    // simulation; the next listHistory call for that inbox pops the
    // entry and throws RateLimitedException.
    /** @var array<int, int> */
    private array $historyRateLimitedInboxes = [];

    // Queued success-shaped listHistory response. When set, the next
    // listHistory call returns this payload verbatim instead of
    // replaying the 404 fixture; set via queueHistoryResponse() and
    // consumed on first use.
    /** @var array{history: list<array<string, mixed>>, historyId: ?string}|null */
    private ?array $queuedHistoryResponse = null;

    // Queued listDiscoveryCandidates responses. Each call shifts the
    // front entry; once empty, the default three-row fixture is
    // replayed.
    /** @var list<array{messages: list<array<string, mixed>>, nextPageToken: ?string}> */
    private array $queuedDiscoveryResponses = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $fixtureRoot = __DIR__.'/../../tests/fixtures/api-responses/gmail',
    ) {}

    // Replays users.messages.list with q=from:(...): the first call
    // per inbox returns the page-1 fixture; the second call returns
    // the empty page-2 sentinel, and every call after that keeps
    // replaying the empty sentinel.
    /**
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, historyId: ?string, resultSizeEstimate: int}
     */
    public function listSenderMessages(
        int $inboxId,
        array $senderPatterns,
        ?string $pageToken,
        ?DateTimeImmutable $windowStart = null,
    ): array {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'senderPatterns' => $senderPatterns,
            'pageToken' => $pageToken,
            'windowStart' => $windowStart?->format(\DateTimeInterface::ATOM),
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

    // Replays users.messages.get?format=raw and returns the
    // base64url-decoded RFC 822 byte stream, so the caller sees the
    // same payload shape the real client surfaces after unwrapping
    // Gmail's transport encoding.
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
            throw new FixtureUnusableException(
                "Fake Gmail fixture {$fixture} has no `raw` field for message {$providerMessageId}.",
            );
        }

        return self::base64UrlDecode($raw);
    }

    // Replays users.history.list, throwing CursorExpiredException via
    // the default 404 fixture so the caller exercises the fallback
    // path; queueHistoryResponse() and simulateHistoryRateLimit() arm
    // alternate success/rate-limit shapes for targeted tests.
    /**
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

    // Replays the discovery query with one entry per message carrying
    // parsed sender address + name + internalDate (never the raw .eml
    // body). queueDiscoveryResponse() overrides the next call's
    // result; simulateRateLimit() arms the shared rate-limit pool.
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array<string, mixed>>, nextPageToken: ?string}
     */
    public function listDiscoveryCandidates(
        int $inboxId,
        array $keywords,
        array $excludeSenders,
        ?string $pageToken = null,
    ): array {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'keywords' => $keywords,
            'excludeSenders' => $excludeSenders,
            'pageToken' => $pageToken,
        ]];

        $this->maybeThrowRateLimit($inboxId);

        if ($this->queuedDiscoveryResponses !== []) {
            return array_shift($this->queuedDiscoveryResponses);
        }

        // Default fixture replay: synthesises the three sender
        // metadata rows any test calling listDiscoveryCandidates
        // without queueing keeps seeing. Dates match the .eml fixtures
        // so other callers can cross-reference.
        return [
            'messages' => [
                ['id' => 'paypal-sample-receipt', 'fromAddress' => 'service@paypal.com', 'fromName' => 'PayPal', 'internalDate' => '2026-05-11T09:14:21Z'],
                ['id' => 'ics-sample-statement-notice', 'fromAddress' => 'noreply@ics.nl', 'fromName' => 'ICS Cards', 'internalDate' => '2026-05-12T06:00:13Z'],
                ['id' => 'googleplay-sample-purchase', 'fromAddress' => 'googleplay-noreply@google.com', 'fromName' => 'Google Play', 'internalDate' => '2026-05-13T17:45:49Z'],
            ],
            'nextPageToken' => null,
        ];
    }

    // Queues the next listDiscoveryCandidates response (FIFO); once
    // the queue empties, calls fall back to the default three-row
    // fixture.
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function queueDiscoveryResponse(array $messages, ?string $nextPageToken = null): void
    {
        $this->queuedDiscoveryResponses[] = [
            'messages' => $messages,
            'nextPageToken' => $nextPageToken,
        ];
    }

    // Arms a rate-limit simulation: the next listSenderMessages or
    // listDiscoveryCandidates call for the given inbox pops the armed
    // entry and throws RateLimitedException with the configured
    // retry-after.
    public function simulateRateLimit(int $inboxId, int $retryAfterSeconds = 2): void
    {
        $this->rateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    // Arms a rate-limit response for the next listHistory call.
    // IncrementalScanJob calls listHistory before listSenderMessages,
    // so a Gmail incremental-scan rate-limit fires via this surface
    // rather than via simulateRateLimit (which targets listSenderMessages).
    public function simulateHistoryRateLimit(int $inboxId, int $retryAfterSeconds = 60): void
    {
        $this->historyRateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    // Queues the next listHistory response so the caller sees a
    // success shape instead of the default 404 fixture; the
    // messages-added list is materialised from $messageIds so the
    // caller's downstream getRawMessage walk has fixture-mapped blobs.
    /**
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

    // Maps an opaque provider message-id to the fixture slug used in
    // the messages-get-raw-*.json filename. The synthesised corpus
    // stores ids like "paypal-sample-receipt"; the fixture slug is
    // the first dash-segment.
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
            throw new FixtureUnusableException('Invalid base64url payload in Gmail fixture.');
        }

        return $decoded;
    }
}
