<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;

final class FakeGraphApiClient implements GraphApiClientContract
{
    /** @var list<array{method: string, args: array<int|string, mixed>}> */
    private array $calls = [];

    /** @var array<int, int> */
    private array $rateLimitedInboxes = [];

    /** @var array<int, true> */
    private array $cursorExpiredInboxes = [];

    // Inbox-id-keyed retry-after seconds for a deltaPage rate-limit
    // simulation; the next deltaPage call for that inbox pops the
    // entry and throws RateLimitedException.
    /** @var array<int, int> */
    private array $deltaRateLimitedInboxes = [];

    // Queued non-empty deltaPage responses. Each call to deltaPage
    // shifts the front entry; once empty, the default baseline
    // fixture is returned.
    /** @var list<array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}> */
    private array $queuedDeltaResponses = [];

    // Queued listDiscoveryCandidatesPaged responses. Each call shifts
    // the front entry; once empty, the default fixture is replayed.
    /** @var list<array{messages: list<array<string, mixed>>, nextLink: ?string}> */
    private array $queuedDiscoveryResponses = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $fixtureRoot = __DIR__.'/../../tests/fixtures/api-responses/graph',
    ) {}

    // Replays paged /me/messages with a from-address filter: the
    // first call per inbox returns the page-1 fixture, subsequent
    // calls return the empty page-2 sentinel.
    /**
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
        $rawNext = $payload['@odata.nextLink'] ?? null;
        $next = is_string($rawNext) ? $rawNext : null;

        return [
            'messages' => $messages,
            'nextLink' => $next,
        ];
    }

    // Replays /messages/{id}/$value. Graph returns the raw RFC 822
    // MIME stream directly (no base64 transport wrapper), so the Fake
    // reads the matching .eml fixture verbatim.
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

    // Replays the $delta endpoint. Default behaviour returns the
    // baseline empty delta page with a deltaLink; tests that arm
    // simulateCursorExpired() get the 410/syncStateNotFound shape
    // replayed as CursorExpiredException.
    /**
     * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
     */
    public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'deltaLink' => $deltaLink,
            'sinceOverride' => $sinceOverride?->format(\DateTimeInterface::ATOM),
        ]];

        if (array_key_exists($inboxId, $this->deltaRateLimitedInboxes)) {
            $retryAfter = $this->deltaRateLimitedInboxes[$inboxId];
            unset($this->deltaRateLimitedInboxes[$inboxId]);
            $payload = $this->readJson('throttle-429.json');
            $error = $payload['error'] ?? null;
            $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Microsoft Graph rate limit exceeded.';
            throw new RateLimitedException($retryAfter, $message);
        }

        if (array_key_exists($inboxId, $this->cursorExpiredInboxes)) {
            unset($this->cursorExpiredInboxes[$inboxId]);
            $payload = $this->readJson('delta-410.json');
            $error = $payload['error'] ?? null;
            $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : '';
            throw CursorExpiredException::graph($message);
        }

        if ($this->queuedDeltaResponses !== []) {
            return array_shift($this->queuedDeltaResponses);
        }

        $payload = $this->readJson('delta-baseline.json');
        /** @var list<array<string, mixed>> $messages */
        $messages = is_array($payload['value'] ?? null) ? $payload['value'] : [];
        $rawDelta = $payload['@odata.deltaLink'] ?? null;
        $rawNext = $payload['@odata.nextLink'] ?? null;

        return [
            'messages' => $messages,
            'deltaLink' => is_string($rawDelta) ? $rawDelta : null,
            'nextLink' => is_string($rawNext) ? $rawNext : null,
        ];
    }

    // Replays the discovery query. Default fixture is the three
    // sender entries from messages-page-1.json, each carrying
    // from.emailAddress.{address,name} + receivedDateTime inline so
    // discovery can populate discovered_senders without a body fetch.
    /**
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

        $this->maybeThrowRateLimit($inboxId);

        if ($this->queuedDiscoveryResponses !== []) {
            return array_shift($this->queuedDiscoveryResponses);
        }

        $payload = $this->readJson('messages-page-1.json');
        /** @var list<array<string, mixed>> $messages */
        $messages = is_array($payload['value'] ?? null) ? $payload['value'] : [];

        return [
            'messages' => $messages,
            'nextLink' => null,
        ];
    }

    // Queues the next listDiscoveryCandidatesPaged response; each
    // call consumes the queue's front entry, and once empty the
    // default messages-page-1.json fixture is replayed.
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function queueDiscoveryResponse(array $messages, ?string $nextLink = null): void
    {
        $this->queuedDiscoveryResponses[] = [
            'messages' => $messages,
            'nextLink' => $nextLink,
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

    // Arms a rate-limit response for the next deltaPage call, since
    // IncrementalScanJob's Microsoft branch calls deltaPage() before
    // listSenderMessagesPaged (which simulateRateLimit targets).
    public function simulateDeltaRateLimit(int $inboxId, int $retryAfterSeconds = 60): void
    {
        $this->deltaRateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    // Queues the next deltaPage response so the caller sees a
    // specific set of messages + new deltaLink; calls past the queued
    // entries fall back to the default baseline fixture.
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function queueDeltaResponse(array $messages, ?string $newDeltaLink, ?string $nextLink = null): void
    {
        $this->queuedDeltaResponses[] = [
            'messages' => $messages,
            'deltaLink' => $newDeltaLink,
            'nextLink' => $nextLink,
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
        $payload = $this->readJson('throttle-429.json');
        $error = $payload['error'] ?? null;
        $message = is_array($error) && isset($error['message']) && is_string($error['message'])
            ? $error['message']
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
            throw new FixtureUnusableException('EmailScan fixture eml root not found.');
        }
        $map = [
            'paypal' => $emlRoot.'/paypal/sample-receipt.eml',
            'ics' => $emlRoot.'/ics/sample-statement-notice.eml',
            'googleplay' => $emlRoot.'/googleplay/sample-purchase.eml',
        ];
        if (! array_key_exists($slug, $map)) {
            throw new FixtureUnusableException("Fake Graph fixture has no .eml for slug `{$slug}`.");
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
