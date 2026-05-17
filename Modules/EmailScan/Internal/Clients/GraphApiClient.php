<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Production wrapper over Microsoft Graph's REST surface.
 *
 * Mirrors the public method shape `FakeGraphApiClient` surfaces in
 * Wave 0 so test wiring can swap a Fake instance into the container
 * binding for `GraphApiClientContract` without any contract drift.
 *
 * **Why thin Guzzle, not the Kiota SDK?**
 *
 * `microsoft/microsoft-graph` v3 is a Kiota-generated SDK whose entry
 * point (`Microsoft\Graph\GraphServiceClient`) expects a Kiota
 * authentication provider that itself wraps a TokenRequestContext
 * implementation. That two-layer abstraction is designed for delegated
 * MSAL flows where the SDK refreshes tokens itself. The project's
 * OAuth surface already owns the refresh cycle via
 * `MicrosoftOAuthProvider::refreshAccessToken` + the chmod-600 JSON
 * repository; reusing that surface keeps token storage in one place
 * and avoids the SDK's request-builder hierarchy entirely for the
 * four read-only endpoints this client needs. Direct Guzzle gives us:
 *
 *   - one HTTP boundary to audit for `Authorization: Bearer` header
 *     leaks (the catch blocks strip the header before re-throwing),
 *   - explicit control over the OData `$filter` string and the
 *     `@odata.nextLink` walk semantics,
 *   - explicit control over the `Retry-After` header read on 429
 *     (the SDK swallows the header into a generic exception object).
 *
 * Error mapping:
 *
 *  - HTTP 429 (TooManyRequests) → `RateLimitedException` carrying the
 *    `Retry-After` header value (seconds form OR HTTP-date form;
 *    HTTP-date is converted to seconds against the injected Clock).
 *  - HTTP 410 / `syncStateNotFound` on `deltaPage()` →
 *    `CursorExpiredException::graph(...)` so the caller falls back to
 *    a baseline re-establish.
 *  - All other 4xx / 5xx → `\RuntimeException` carrying the HTTP
 *    status + the safe error message (provider's `error.message` if
 *    present; never the request headers, never the bearer token).
 *  - Token payloads NEVER appear in any thrown exception message.
 *
 * `listDiscoveryCandidatesPaged` is part of the contract but its
 * production body lands in a later plan; this client returns an empty
 * page so any caller wiring against the contract compiles without
 * exercising live keyword search.
 */
final class GraphApiClient implements GraphApiClientContract
{
    private const GRAPH_BASE_URI = 'https://graph.microsoft.com/v1.0/';

    /** Allow-list for the providerMessageId path segment in /messages/{id}/$value. */
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly MicrosoftOAuthProvider $oauth,
        private readonly Clock $clock,
    ) {}

    /**
     * Backfill walk over `/me/messages` with an OData `$filter` that
     * constrains the result set to known-sender addresses + a
     * receivedDateTime lower bound. Subsequent pages follow the
     * `@odata.nextLink` URL verbatim (the URL embeds the skip token
     * and the original filter).
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
        if ($senderPatterns === []) {
            return ['messages' => [], 'nextLink' => null];
        }

        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        if ($nextLink !== null && $nextLink !== '') {
            // Follow the prior page's @odata.nextLink URL verbatim —
            // Graph embeds the skip token + the original filter inside
            // the URL, so reconstructing the query parameters would be
            // wrong (and lossy on the skip token's opaque value).
            $url = $nextLink;
            $body = $this->getJson($client, $accessToken, $url);
        } else {
            $filter = $this->buildSenderFilter($senderPatterns, $windowStart);
            $body = $this->getJson($client, $accessToken, self::GRAPH_BASE_URI.'me/messages', [
                '$filter' => $filter,
                '$orderby' => 'receivedDateTime desc',
                '$top' => '100',
                '$select' => 'id,from,subject,receivedDateTime',
            ]);
        }

        $messages = $this->collectMessages($body);
        $rawNext = $body['@odata.nextLink'] ?? null;
        $next = is_string($rawNext) && $rawNext !== '' ? $rawNext : null;

        return [
            'messages' => $messages,
            'nextLink' => $next,
        ];
    }

    /**
     * Fetch the raw RFC 822 byte stream for one message via
     * `/messages/{id}/$value`. Graph returns the bytes directly — no
     * base64 decode, no JSON envelope. The path segment is validated
     * against an allow-list before interpolation to defend against a
     * crafted message id carrying path-traversal payloads.
     */
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        if (preg_match(self::MESSAGE_ID_PATTERN, $providerMessageId) !== 1) {
            throw new RuntimeException(
                'GraphApiClient: provider message id failed allow-list validation.',
            );
        }

        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        try {
            $response = $client->request('GET', self::GRAPH_BASE_URI.'me/messages/'.$providerMessageId.'/$value', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'message/rfc822, */*',
                ],
                'http_errors' => true,
            ]);
        } catch (BadResponseException $e) {
            throw $this->mapErrorResponse($e->getResponse(), 'GET /me/messages/{id}/$value');
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'GraphApiClient: HTTP error fetching raw message — '.$this->safeMessage($e->getMessage()),
            );
        }

        return (string) $response->getBody();
    }

    /**
     * Establish or walk the `/me/mailFolders/inbox/messages/delta`
     * cursor.
     *
     * On baseline establish (`$deltaLink === null`) the first call is
     * issued against the inbox folder delta endpoint with a
     * `$filter=receivedDateTime ge {now}` predicate; the first
     * response carries an empty `value` array plus the
     * `@odata.deltaLink` the caller persists.
     *
     * On incremental walk (`$deltaLink !== null`) the URL is followed
     * verbatim — Graph embeds the cursor token in the URL.
     *
     * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
     */
    public function deltaPage(int $inboxId, ?string $deltaLink): array
    {
        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        if ($deltaLink !== null && $deltaLink !== '') {
            $url = $deltaLink;
            $body = $this->getJson($client, $accessToken, $url, [], expectsDelta: true);
        } else {
            $nowIso = $this->clock->now()->format('Y-m-d\TH:i:s\Z');
            $body = $this->getJson(
                $client,
                $accessToken,
                self::GRAPH_BASE_URI.'me/mailFolders/inbox/messages/delta',
                ['$filter' => 'receivedDateTime ge '.$nowIso],
                expectsDelta: true,
            );
        }

        $messages = $this->collectMessages($body);
        $rawDelta = $body['@odata.deltaLink'] ?? null;
        $rawNext = $body['@odata.nextLink'] ?? null;

        return [
            'messages' => $messages,
            'deltaLink' => is_string($rawDelta) && $rawDelta !== '' ? $rawDelta : null,
            'nextLink' => is_string($rawNext) && $rawNext !== '' ? $rawNext : null,
        ];
    }

    /**
     * Reserved for the discovery scan plan. Returns an empty page so
     * the discovery loop is inert until the matcher phase wires the
     * real keyword query.
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
        unset($inboxId, $keywords, $excludeSenders, $nextLink);

        return [
            'messages' => [],
            'nextLink' => null,
        ];
    }

    /**
     * Build the OData `$filter` clause that constrains a backfill page
     * to the configured sender allow-list plus the receivedDateTime
     * lower bound. OData escapes single quotes inside a string literal
     * by doubling them (e.g. `o'brien` → `o''brien`); the helper
     * applies that rule before interpolating each pattern.
     *
     * Example output for two senders and a 3-month window:
     *
     *   (from/emailAddress/address eq 'service@paypal.com' or
     *    from/emailAddress/address eq 'noreply@ics.nl') and
     *   receivedDateTime ge 2026-02-17T00:00:00Z
     *
     * @param  list<string>  $senderPatterns
     */
    private function buildSenderFilter(array $senderPatterns, DateTimeImmutable $windowStart): string
    {
        $clauses = array_map(
            static fn (string $p): string => "from/emailAddress/address eq '".str_replace("'", "''", $p)."'",
            $senderPatterns,
        );

        return '('.implode(' or ', $clauses).') and receivedDateTime ge '
            .$windowStart->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Issue a GET, return the JSON-decoded response body as an array.
     * Maps Graph's error envelope to the project's typed sentinels.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function getJson(
        GuzzleClient $client,
        string $accessToken,
        string $url,
        array $query = [],
        bool $expectsDelta = false,
    ): array {
        try {
            $response = $client->request('GET', $url, [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'application/json',
                ],
                'http_errors' => true,
            ]);
        } catch (BadResponseException $e) {
            throw $this->mapErrorResponse($e->getResponse(), 'GET '.$url, $expectsDelta);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'GraphApiClient: HTTP error against '.$url.' — '.$this->safeMessage($e->getMessage()),
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'GraphApiClient: failed to decode Graph response JSON ('.$e->getMessage().').',
            );
        }

        if (! is_array($decoded)) {
            return [];
        }

        // Narrow array<mixed, mixed> -> array<string, mixed> — the top-
        // level Graph response is always a JSON object, but PHPStan's
        // strict mode cannot infer key shape from json_decode.
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Translate a non-2xx response into the right typed sentinel.
     *  - 429 → RateLimitedException with Retry-After parsed.
     *  - 410 / syncStateNotFound on a delta call → CursorExpiredException.
     *  - everything else → RuntimeException with the safe error message.
     */
    private function mapErrorResponse(
        ?ResponseInterface $response,
        string $context,
        bool $expectsDelta = false,
    ): RuntimeException {
        if ($response === null) {
            return new RuntimeException(
                'GraphApiClient: '.$context.' — provider returned no response.',
            );
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $safeBodyMessage = $this->extractErrorMessage($body);

        if ($status === 429) {
            $retryAfterHeader = $response->getHeaderLine('Retry-After');
            $retryAfterSeconds = $this->parseRetryAfter($retryAfterHeader);

            return new RateLimitedException(
                retryAfterSeconds: $retryAfterSeconds,
                message: 'Microsoft Graph rate limit exceeded: '.$safeBodyMessage,
            );
        }

        if ($expectsDelta && $status === 410) {
            return CursorExpiredException::graph($safeBodyMessage);
        }

        return new RuntimeException(
            'GraphApiClient: '.$context.' returned HTTP '.$status.' — '.$safeBodyMessage,
        );
    }

    /**
     * Parse the Retry-After header into a seconds value. Graph
     * documents the header as "delta-seconds" (an integer count of
     * seconds), but the broader HTTP spec also allows an HTTP-date
     * — convert the date form against the injected Clock so the
     * caller always receives a normalised second count.
     *
     * Falls back to a 60-second default when the header is missing
     * or unparseable.
     */
    private function parseRetryAfter(string $header): int
    {
        $trimmed = trim($header);
        if ($trimmed === '') {
            return 60;
        }

        // delta-seconds form (the common Graph response shape).
        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $seconds = (int) $trimmed;

            return $seconds > 0 ? $seconds : 60;
        }

        // HTTP-date form (rare but spec-permitted).
        $when = strtotime($trimmed);
        if ($when === false) {
            return 60;
        }
        $delta = $when - $this->clock->now()->getTimestamp();

        return $delta > 0 ? $delta : 60;
    }

    /**
     * Extract `error.message` from a Graph error body without ever
     * including request headers or the bearer token in the result.
     * Caps the surfaced message so a verbose IdP error cannot
     * contaminate any logging surface above.
     */
    private function extractErrorMessage(string $rawBody): string
    {
        if ($rawBody === '') {
            return 'no body returned';
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->safeMessage($rawBody);
        }
        if (! is_array($decoded)) {
            return 'unrecognised error body';
        }
        $err = $decoded['error'] ?? null;
        if (! is_array($err)) {
            return 'unrecognised error body';
        }
        $msg = $err['message'] ?? null;
        if (is_string($msg) && $msg !== '') {
            return $this->safeMessage($msg);
        }
        $code = $err['code'] ?? null;
        if (is_string($code) && $code !== '') {
            return $this->safeMessage($code);
        }

        return 'unrecognised error body';
    }

    /**
     * Cap the surfaced message at 300 characters and strip newlines so
     * verbose provider errors cannot contaminate a flash session
     * payload or a log line.
     */
    private function safeMessage(string $raw): string
    {
        $oneLine = (string) preg_replace('/\s+/', ' ', $raw);

        return substr($oneLine, 0, 300);
    }

    /**
     * Walk the `value` array of a Graph response body, narrowing each
     * entry to `array<string, mixed>` and passing it through
     * `normaliseMessageMeta`. Non-array entries (a hostile or
     * malformed response would be the only producer) are skipped.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function collectMessages(array $body): array
    {
        $rawValue = $body['value'] ?? null;
        if (! is_array($rawValue)) {
            return [];
        }

        $messages = [];
        foreach ($rawValue as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $narrowed = [];
            foreach ($raw as $k => $v) {
                $narrowed[(string) $k] = $v;
            }
            $messages[] = $this->normaliseMessageMeta($narrowed);
        }

        return $messages;
    }

    /**
     * Normalise a Graph message object into the shape the
     * `BackfillInboxJob` consumes: id + fromAddress + fromName +
     * subject + receivedDateTime. The FakeGraphApiClient already
     * returns this exact shape (it reads the raw fixture array), so
     * by normalising at the production boundary the two paths stay
     * type-identical at the call site.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normaliseMessageMeta(array $raw): array
    {
        $from = $raw['from'] ?? [];
        $emailAddress = is_array($from) && isset($from['emailAddress']) && is_array($from['emailAddress'])
            ? $from['emailAddress']
            : [];

        $addr = $emailAddress['address'] ?? null;
        $name = $emailAddress['name'] ?? null;
        $subject = $raw['subject'] ?? null;
        $received = $raw['receivedDateTime'] ?? null;
        $id = $raw['id'] ?? null;

        return [
            'id' => is_string($id) ? $id : '',
            'from' => [
                'emailAddress' => [
                    'address' => is_string($addr) ? $addr : '',
                    'name' => is_string($name) && $name !== '' ? $name : null,
                ],
            ],
            'subject' => is_string($subject) && $subject !== '' ? $subject : null,
            'receivedDateTime' => is_string($received) ? $received : '',
        ];
    }

    /**
     * Return a non-expired access token for the inbox, refreshing via
     * the OAuth provider when the cached token is missing or within
     * 60 seconds of its stamped expiry. Microsoft rotates refresh
     * tokens single-use on every exchange, so the fresh refresh token
     * is persisted via OAuthSecretsRepository::rotateRefreshToken.
     */
    private function ensureFreshAccessToken(int $inboxId): string
    {
        $creds = $this->secrets->loadInbox($inboxId);
        if ($creds === null) {
            throw new RuntimeException(
                "GraphApiClient: no OAuth credentials persisted for inbox {$inboxId}.",
            );
        }

        $nowTs = $this->clock->now()->getTimestamp();
        $expiresTs = $creds->expiresAt?->getTimestamp();
        $cachedAccessToken = $creds->accessToken;

        if (
            $cachedAccessToken === null
            || $cachedAccessToken === ''
            || $expiresTs === null
            || $expiresTs < $nowTs + 60
        ) {
            $fresh = $this->oauth->refreshAccessToken($creds->refreshToken);
            // Microsoft Graph rotates the refresh token on every
            // refresh; persist the new pair via the repository's
            // atomic rotateRefreshToken hook (the underlying writeAtomic
            // makes the rotation crash-safe).
            $this->secrets->rotateRefreshToken(
                $inboxId,
                $fresh->refreshToken ?? $creds->refreshToken,
                $fresh->accessToken,
                $fresh->expiresAt,
            );

            return $fresh->accessToken;
        }

        return $cachedAccessToken;
    }

    /**
     * Lazily instantiate a Guzzle client. Kept as a private factory
     * (rather than a constructor-injected dependency) so a future test
     * subclass could override the HTTP boundary without changing the
     * public constructor signature. The Fake takes the same role at
     * the contract layer for the current test surface.
     */
    private function makeHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }
}
