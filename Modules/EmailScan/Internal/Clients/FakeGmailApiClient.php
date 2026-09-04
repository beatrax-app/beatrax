<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

final class FakeGmailApiClient implements GmailApiClientContract
{
    /** @var list<array{method: string, args: array<int|string, mixed>}> */
    private array $calls = [];

    /** @var array<int, int> */
    private array $rateLimitedInboxes = [];

    /** @var array<int, int> */
    private array $listPageCursor = [];

    /** @var array<int, int> */
    private array $historyRateLimitedInboxes = [];

    /** @var list<string> */
    private array $unavailableMessageIds = [];

    /** @var list<string> */
    private array $undecodableMessageIds = [];

    /** @var array{history: list<array<string, mixed>>, historyId: ?string}|null */
    private ?array $queuedHistoryResponse = null;

    private ?string $currentHistoryId = '12345';

    /** @var list<array{messages: list<array<string, mixed>>, nextPageToken: ?string}> */
    private array $queuedDiscoveryResponses = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $fixtureRoot = __DIR__.'/../../tests/fixtures/api-responses/gmail',
    ) {}

    // Call 1 per inbox returns the page-1 fixture; every call after that
    // returns the empty page-2 sentinel.
    /**
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, resultSizeEstimate: int}
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
            'resultSizeEstimate' => $estimate,
        ];
    }

    public function currentHistoryId(int $inboxId): ?string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => ['inboxId' => $inboxId]];

        return $this->currentHistoryId;
    }

    // Standing in for a mailbox whose profile endpoint is unreachable, which
    // must leave the cursor unset rather than write an empty one.
    public function simulateUnknownHistoryId(): void
    {
        $this->currentHistoryId = null;
    }

    // Returns decoded RFC 822 bytes, matching what the real client hands
    // back once Gmail's base64url transport encoding is unwrapped.
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'providerMessageId' => $providerMessageId,
        ]];

        if (in_array($providerMessageId, $this->unavailableMessageIds, strict: true)) {
            throw new MessageUnavailableException(
                "FakeGmailApiClient: message {$providerMessageId} is no longer available on inbox {$inboxId}.",
            );
        }

        if (in_array($providerMessageId, $this->undecodableMessageIds, strict: true)) {
            throw new GmailRawDecodeException(
                'FakeGmailApiClient: failed to base64url-decode message raw payload.',
            );
        }

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

    // The default 404 fixture throws CursorExpiredException, so an
    // un-armed caller exercises the fallback path.
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
            if (is_int($code) && $code === Response::HTTP_NOT_FOUND) {
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
     * @param  list<array<string, mixed>>  $messages
     */
    public function queueDiscoveryResponse(array $messages, ?string $nextPageToken = null): void
    {
        $this->queuedDiscoveryResponses[] = [
            'messages' => $messages,
            'nextPageToken' => $nextPageToken,
        ];
    }

    // Stands in for a message deleted between the history read and the fetch,
    // which the real client surfaces as a 404 from users.messages.get.
    public function simulateMissingMessage(string $providerMessageId): void
    {
        $this->unavailableMessageIds[] = $providerMessageId;
    }

    // The other half of that pair, and not the same fact: the payload arrived
    // and base64UrlDecode() refused it, so the bytes were received and lost
    // rather than never handed over.
    public function simulateUndecodableMessage(string $providerMessageId): void
    {
        $this->undecodableMessageIds[] = $providerMessageId;
    }

    public function simulateRateLimit(int $inboxId, int $retryAfterSeconds = 2): void
    {
        $this->rateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    // IncrementalScanJob calls listHistory before listSenderMessages, so a
    // Gmail incremental rate-limit has to be armed here, not via
    // simulateRateLimit().
    public function simulateHistoryRateLimit(int $inboxId, int $retryAfterSeconds = 60): void
    {
        $this->historyRateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    // The messages-added list is materialised from $messageIds so the
    // caller's getRawMessage walk finds fixture-mapped blobs.
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

    // "paypal-sample-receipt" resolves to messages-get-raw-paypal.json:
    // the slug is the first dash-segment of the id.
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
