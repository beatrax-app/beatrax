<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Fixture-driven fake of the future real Microsoft Graph API client.
 * Replays canned JSON responses from
 * `Modules/EmailScan/tests/fixtures/api-responses/graph/` so plans
 * that wire the production pipeline can drive end-to-end tests
 * without a real Microsoft consent flow.
 *
 * Wave 0 ships this Fake before the production `GraphApiClient`
 * interface exists. The public method signatures here ARE the
 * contract — later plans ship the real client and the formal
 * interface; both must adopt the same shape so the test seam stays
 * drop-in compatible.
 */
final class FakeGraphApiClient
{
    /**
     * @var list<array{method: string, args: array<int|string, mixed>}>
     */
    private array $calls = [];

    /** @var array<int, int> */
    private array $rateLimitedInboxes = [];

    /** @var array<int, true> */
    private array $cursorExpiredInboxes = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $fixtureRoot = __DIR__.'/../../tests/fixtures/api-responses/graph',
    ) {}

    /**
     * Replays paged `/me/messages` with a from-address filter. First
     * call per inbox returns the page-1 fixture; subsequent calls
     * return the empty page-2 sentinel.
     *
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
     */
    public function listSenderMessagesPaged(
        int $inboxId,
        array $senderPatterns,
        DateTimeImmutable $windowStart,
        ?string $nextLink,
    ): array {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'senderPatterns' => $senderPatterns,
            'windowStart' => $windowStart->format(DATE_ATOM),
            'nextLink' => $nextLink,
        ]];

        $this->maybeThrowRateLimit($inboxId);

        $fixture = $nextLink === null
            ? 'messages-page-1.json'
            : 'messages-page-2-empty.json';
        $payload = $this->readJson($fixture);

        /** @var list<array<string, mixed>> $messages */
        $messages = is_array($payload['value'] ?? null) ? $payload['value'] : [];
        $next = is_string($payload['@odata.nextLink'] ?? null) ? (string) $payload['@odata.nextLink'] : null;

        return [
            'messages' => $messages,
            'nextLink' => $next,
        ];
    }

    /**
     * Replays `/messages/{id}/$value`. Graph returns the raw RFC 822
     * MIME stream directly (no base64 transport wrapper), so the
     * Fake reads the matching `.eml` fixture verbatim.
     */
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'providerMessageId' => $providerMessageId,
        ]];

        $slug = $this->fixtureSlug($providerMessageId);
        $emlPath = $this->resolveEmlPath($slug);
        $contents = $this->files->get($emlPath);

        // Normalise to CRLF wire form so the byte stream matches what
        // the real Graph endpoint returns (RFC 822 wire format).
        return self::normaliseCrlf($contents);
    }

    /**
     * Replays the `$delta` endpoint. Default behaviour returns the
     * baseline empty delta page with a deltaLink. Tests that arm
     * `simulateCursorExpired()` get the 410 / syncStateNotFound shape
     * replayed as CursorExpiredException.
     *
     * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
     */
    public function deltaPage(int $inboxId, ?string $deltaLink): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'deltaLink' => $deltaLink,
        ]];

        if (array_key_exists($inboxId, $this->cursorExpiredInboxes)) {
            unset($this->cursorExpiredInboxes[$inboxId]);
            $payload = $this->readJson('delta-410.json');
            $message = is_array($payload['error'] ?? null) && is_string($payload['error']['message'] ?? null)
                ? (string) $payload['error']['message']
                : '';
            throw CursorExpiredException::graph($message);
        }

        $payload = $this->readJson('delta-baseline.json');
        /** @var list<array<string, mixed>> $messages */
        $messages = is_array($payload['value'] ?? null) ? $payload['value'] : [];

        return [
            'messages' => $messages,
            'deltaLink' => is_string($payload['@odata.deltaLink'] ?? null) ? (string) $payload['@odata.deltaLink'] : null,
            'nextLink' => is_string($payload['@odata.nextLink'] ?? null) ? (string) $payload['@odata.nextLink'] : null,
        ];
    }

    /**
     * Replays the discovery query. Wave 0 reuses the page-1 fixture.
     *
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
     */
    public function listDiscoveryCandidatesPaged(
        int $inboxId,
        array $keywords,
        array $excludeSenders,
        ?string $nextLink,
    ): array {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'keywords' => $keywords,
            'excludeSenders' => $excludeSenders,
            'nextLink' => $nextLink,
        ]];

        $payload = $this->readJson('messages-page-1.json');
        /** @var list<array<string, mixed>> $messages */
        $messages = is_array($payload['value'] ?? null) ? $payload['value'] : [];

        return [
            'messages' => $messages,
            'nextLink' => null,
        ];
    }

    public function simulateRateLimit(int $inboxId, int $retryAfterSeconds = 2): void
    {
        $this->rateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    public function simulateCursorExpired(int $inboxId): void
    {
        $this->cursorExpiredInboxes[$inboxId] = true;
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
        $payload = $this->readJson('throttle-429.json');
        $message = is_array($payload['error'] ?? null) && is_string($payload['error']['message'] ?? null)
            ? (string) $payload['error']['message']
            : 'Microsoft Graph rate limit exceeded.';
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

    private function resolveEmlPath(string $slug): string
    {
        $emlRoot = realpath($this->fixtureRoot.'/../../eml');
        if ($emlRoot === false) {
            throw new RuntimeException('EmailScan fixture eml root not found.');
        }
        $map = [
            'paypal' => $emlRoot.'/paypal/sample-receipt.eml',
            'ics' => $emlRoot.'/ics/sample-statement-notice.eml',
            'googleplay' => $emlRoot.'/googleplay/sample-purchase.eml',
        ];
        if (! array_key_exists($slug, $map)) {
            throw new RuntimeException("Fake Graph fixture has no .eml for slug `{$slug}`.");
        }

        return $map[$slug];
    }

    private function fixtureSlug(string $providerMessageId): string
    {
        $dash = strpos($providerMessageId, '-');
        if ($dash === false) {
            return $providerMessageId;
        }

        return substr($providerMessageId, 0, $dash);
    }

    private static function normaliseCrlf(string $contents): string
    {
        return str_replace(["\r\n", "\n"], ["\n", "\r\n"], $contents);
    }
}
