<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;

final class FakeGraphApiClient implements GraphApiClientContract
{
    // The one sentence this fake gives a throttled caller, so a test asserting
    // on it is asserting on the client's whole rate-limit story.
    private const string RATE_LIMIT_MESSAGE = 'Microsoft Graph rate limit exceeded.';

    /** @var list<array{method: string, args: array<int|string, mixed>}> */
    private array $calls = [];

    /** @var array<int, int> */
    private array $rateLimitedInboxes = [];

    /** @var array<int, true> */
    private array $cursorExpiredInboxes = [];

    /** @var array<int, int> */
    private array $deltaRateLimitedInboxes = [];

    /** @var list<array{messages: list<array<string, mixed>>, nextLink: ?string}> */
    private array $queuedDiscoveryResponses = [];

    /** @var list<array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}> */
    private array $queuedDeltaResponses = [];

    /** @var list<array{messages: list<array<string, mixed>>, nextLink: ?string}|int> */
    private array $queuedSenderPages = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $fixtureRoot = __DIR__.'/../../tests/fixtures/api-responses/graph',
    ) {}

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

        if ($this->queuedSenderPages !== []) {
            $queued = array_shift($this->queuedSenderPages);
            if (is_int($queued)) {
                throw new RateLimitedException($queued, self::RATE_LIMIT_MESSAGE);
            }

            return $queued;
        }

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

    // Graph hands back the raw RFC 822 stream with no base64 transport
    // wrapper, so the .eml fixture is replayed verbatim.
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [
            'inboxId' => $inboxId,
            'providerMessageId' => $providerMessageId,
        ]];

        $slug = $this->fixtureSlug($providerMessageId);
        $emlPath = $this->resolveEmlPath($slug);
        $contents = $this->files->get($emlPath);

        return self::normaliseCrlf($contents);
    }

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
                : self::RATE_LIMIT_MESSAGE;
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

        // A baseline call carries no stored link and legitimately answers an
        // empty page; a walk from a stored cursor answers a real delta page.
        $payload = $this->readJson($deltaLink === null ? 'delta-baseline.json' : 'delta-page-1.json');
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

    // The default fixture carries from.emailAddress.{address,name} and
    // receivedDateTime inline, so discovery never needs a body fetch.
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

    // Graph splits a delta across pages exactly as it splits a message list:
    // only the final page carries @odata.deltaLink. Queue the pages a scenario
    // needs; the fixture default stands in for the single-page case.
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function queueDeltaResponse(array $messages, ?string $deltaLink, ?string $nextLink = null): void
    {
        $this->queuedDeltaResponses[] = [
            'messages' => $messages,
            'deltaLink' => $deltaLink,
            'nextLink' => $nextLink,
        ];
    }

    // An empty page mid-walk is legal for both providers, and the page-1 /
    // page-2-empty fixture pair cannot express one.
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function queueSenderPage(array $messages, ?string $nextLink = null): void
    {
        $this->queuedSenderPages[] = [
            'messages' => $messages,
            'nextLink' => $nextLink,
        ];
    }

    // Interrupts the walk at a chosen page rather than at its first call, so a
    // test can prove the retry resumes from the page cursor it stopped on.
    public function queueSenderPageRateLimit(int $retryAfterSeconds = 60): void
    {
        $this->queuedSenderPages[] = $retryAfterSeconds;
    }

    public function simulateRateLimit(int $inboxId, int $retryAfterSeconds = 2): void
    {
        $this->rateLimitedInboxes[$inboxId] = $retryAfterSeconds;
    }

    public function simulateCursorExpired(int $inboxId): void
    {
        $this->cursorExpiredInboxes[$inboxId] = true;
    }

    // Separate from simulateRateLimit because IncrementalScanJob's Microsoft
    // branch reaches deltaPage() before listSenderMessagesPaged.
    public function simulateDeltaRateLimit(int $inboxId, int $retryAfterSeconds = 60): void
    {
        $this->deltaRateLimitedInboxes[$inboxId] = $retryAfterSeconds;
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
            : self::RATE_LIMIT_MESSAGE;
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
            'private' => $emlRoot.'/private/sample-private-mail.eml',
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
