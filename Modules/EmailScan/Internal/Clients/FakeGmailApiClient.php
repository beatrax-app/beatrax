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
     * Replays `users.history.list`. The Wave 0 fixture set encodes a
     * 404 response shape; the Fake recognises that and throws
     * CursorExpiredException so callers exercise the fallback path.
     *
     * @return array{history: list<array<string, mixed>>, historyId: ?string}
     */
    public function listHistory(int $inboxId, string $startHistoryId): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'startHistoryId' => $startHistoryId,
        ]];

        $payload = $this->readJson('history-list-404.json');
        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            if (is_int($code) && $code === 404) {
                $message = $error['message'] ?? '';
                throw CursorExpiredException::gmail(is_string($message) ? $message : '');
            }
        }

        // The Wave 0 fixture only ships the 404 shape; this branch
        // exists so the contract surfaces a sensible response shape
        // for later plans that ship a success fixture.
        return [
            'history' => [],
            'historyId' => null,
        ];
    }

    /**
     * Replays the broader discovery query. Wave 0 returns the same
     * three-message page that the primary scan serves, leaving sender
     * filtering up to the caller.
     *
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string}
     */
    public function listDiscoveryCandidates(int $inboxId, array $keywords, array $excludeSenders): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'keywords' => $keywords,
            'excludeSenders' => $excludeSenders,
        ]];

        $payload = $this->readJson('messages-list-page-1.json');
        /** @var list<array{id: string, threadId: string}> $messages */
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

        return [
            'messages' => $messages,
            'nextPageToken' => null,
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
