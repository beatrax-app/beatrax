<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\ReconsentRequiredException;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Public\Exceptions\ProviderTransportException;
use Modules\EmailScan\Public\Exceptions\UnsafeProviderRequestException;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class GraphApiClient implements GraphApiClientContract
{
    private const UNRECOGNISED_ERROR_BODY = 'unrecognised error body';

    private const GRAPH_BASE_URI = 'https://graph.microsoft.com/v1.0/';

    // SSRF host allow-list: every URL the client sends a bearer token
    // against must resolve to one of these hosts over HTTPS. Regional
    // clouds (graph.microsoft.de, graph.microsoft.us) are deliberately
    // excluded — adding one is a reviewed config change, not silent.
    /** @var list<string> */
    private const ALLOWED_HOSTS = ['graph.microsoft.com'];

    // Allow-list for the providerMessageId path segment in
    // /messages/{id}/$value.
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly MicrosoftOAuthProvider $oauth,
        private readonly Clock $clock,
        private readonly EventsDispatcher $events,
        private readonly DatabaseManager $db,
        private readonly ?GuzzleClient $httpClient = null,
    ) {}

    // Backfill walk over /me/messages with an OData $filter
    // constraining the result set to known-sender addresses + a
    // receivedDateTime lower bound; subsequent pages follow
    // @odata.nextLink verbatim.
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

    // Fetches the raw RFC 822 byte stream via /messages/{id}/$value
    // (Graph returns the bytes directly, no base64/JSON envelope); the
    // path segment is allow-list validated before interpolation to
    // defend against a crafted id carrying path-traversal payloads.
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        if (preg_match(self::MESSAGE_ID_PATTERN, $providerMessageId) !== 1) {
            throw new UnsafeProviderRequestException(
                'GraphApiClient: provider message id failed allow-list validation.',
            );
        }

        $url = self::GRAPH_BASE_URI.'me/messages/'.$providerMessageId.'/$value';

        // Defence-in-depth: assert the URL matches the SSRF allow-list
        // before any bearer token is attached, even though this URL is
        // constructed from a constant base + a regex-validated id.
        $this->assertAllowedUrl($url);

        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        try {
            $response = $client->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'message/rfc822, */*',
                ],
                'http_errors' => true,
            ]);
        } catch (BadResponseException $e) {
            throw $this->mapErrorResponse($e->getResponse(), 'GET /me/messages/{id}/$value');
        } catch (GuzzleException $e) {
            throw new ProviderTransportException(
                'GraphApiClient: HTTP error fetching raw message — '.$this->safeMessage($e->getMessage()),
            );
        }

        return (string) $response->getBody();
    }

    // Establishes or walks the /me/mailFolders/inbox/messages/delta
    // cursor: a null $deltaLink issues the baseline call with a
    // $filter=receivedDateTime ge {now} predicate; a non-null
    // $deltaLink is followed verbatim (Graph embeds the cursor in it).
    /**
     * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
     */
    public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
    {
        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        if ($deltaLink !== null && $deltaLink !== '') {
            // The delta cursor URL has its filter baked in; the
            // $sinceOverride is ignored on the walk branch.
            $url = $deltaLink;
            $body = $this->getJson($client, $accessToken, $url, [], expectsDelta: true);
        } else {
            // Baseline establish: prefer the caller's pinned anchor so
            // a multi-hour backfill captures the lower bound before the
            // walk begins, closing the race window where messages
            // arriving mid-walk would otherwise slip past both filters.
            $sinceIso = ($sinceOverride ?? $this->clock->now()->toDateTimeImmutable())
                ->format('Y-m-d\TH:i:s\Z');
            $body = $this->getJson(
                $client,
                $accessToken,
                self::GRAPH_BASE_URI.'me/mailFolders/inbox/messages/delta',
                ['$filter' => 'receivedDateTime ge '.$sinceIso],
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

    // Daily-discovery query: walks /me/messages?$search="subject:(...)"
    // (Graph rejects $filter contains(subject, ...) for the messages
    // collection, so $search is the only supported keyword match, and
    // $orderby is omitted since Graph rejects it alongside $search).
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
        if ($keywords === []) {
            return ['messages' => [], 'nextLink' => null];
        }

        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        if ($nextLink !== null && $nextLink !== '') {
            // Follow the prior page's @odata.nextLink verbatim — the
            // search token and original $search payload are embedded
            // in the URL by Graph.
            $body = $this->getJson($client, $accessToken, $nextLink);
        } else {
            $quotedKeywords = array_map(
                static fn (string $k): string => '"'.str_replace('"', '\\"', $k).'"',
                $keywords,
            );
            // The $search payload uses Graph's KQL-style syntax:
            // subject:(<quoted OR-list>) matches any keyword against
            // the subject field, wrapped in outer double quotes per
            // the Graph $search contract; Guzzle URL-encodes the value.
            $searchClause = '"subject:('.implode(' OR ', $quotedKeywords).')"';

            $body = $this->getJson(
                $client,
                $accessToken,
                self::GRAPH_BASE_URI.'me/messages',
                [
                    '$search' => $searchClause,
                    '$top' => '100',
                    '$select' => 'id,from,subject,receivedDateTime',
                ],
            );
        }

        $messages = $this->collectMessages($body);

        // Client-side exclude-sender filter, applied after the server
        // response since Graph rejects a "not from/..." predicate
        // alongside $search.
        if ($excludeSenders !== []) {
            $messages = self::applyExcludeSenders($messages, $excludeSenders);
        }

        $rawNext = $body['@odata.nextLink'] ?? null;
        $next = is_string($rawNext) && $rawNext !== '' ? $rawNext : null;

        return [
            'messages' => $messages,
            'nextLink' => $next,
        ];
    }

    // Drops any message whose from-address matches an exclude pattern:
    // a pattern starting with '@' matches by domain suffix, otherwise by
    // substring. A message with no readable from-address is kept, since
    // the exclude list can only ever remove a known sender.
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<string>  $excludeSenders
     * @return list<array<string, mixed>>
     */
    private static function applyExcludeSenders(array $messages, array $excludeSenders): array
    {
        $lowerExcludes = array_map('strtolower', $excludeSenders);

        $filtered = [];
        foreach ($messages as $msg) {
            $addr = self::senderAddress($msg);
            if ($addr === '' || ! self::isExcludedSender($addr, $lowerExcludes)) {
                $filtered[] = $msg;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    private static function senderAddress(array $msg): string
    {
        $from = $msg['from'] ?? null;
        $emailAddress = is_array($from) ? ($from['emailAddress'] ?? null) : null;
        $rawAddr = is_array($emailAddress) ? ($emailAddress['address'] ?? null) : null;

        return is_string($rawAddr) ? strtolower($rawAddr) : '';
    }

    /**
     * @param  list<string>  $lowerExcludes
     */
    private static function isExcludedSender(string $addr, array $lowerExcludes): bool
    {
        foreach ($lowerExcludes as $pattern) {
            $matches = str_starts_with($pattern, '@')
                ? str_ends_with($addr, $pattern)
                : str_contains($addr, $pattern);
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    // Builds the OData $filter clause constraining a backfill page to
    // the sender allow-list plus the receivedDateTime lower bound;
    // OData escapes single quotes inside a string literal by doubling
    // them, so the helper applies that rule before interpolating.
    /**
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

    // Issues a GET and returns the JSON-decoded response body,
    // mapping Graph's error envelope to the project's typed sentinels.
    /**
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
        // SSRF guard: refuse to forward the bearer token to any host
        // outside the Graph allow-list. The check sits at the HTTP
        // boundary because a malformed @odata.nextLink/@odata.deltaLink
        // in any single response could otherwise exfiltrate the token.
        $this->assertAllowedUrl($url);

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
            throw new ProviderTransportException(
                'GraphApiClient: HTTP error against '.$url.' — '.$this->safeMessage($e->getMessage()),
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderTransportException(
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

    // Translates a non-2xx response into the right typed sentinel: 429
    // becomes RateLimitedException, 410/syncStateNotFound on a delta
    // call becomes CursorExpiredException, everything else becomes a
    // RuntimeException carrying the safe error message.
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
        $safeBodyMessage = $this->extractErrorMessage((string) $response->getBody());

        return match (true) {
            $status === Response::HTTP_TOO_MANY_REQUESTS => new RateLimitedException(
                retryAfterSeconds: $this->parseRetryAfter($response->getHeaderLine('Retry-After')),
                message: 'Microsoft Graph rate limit exceeded: '.$safeBodyMessage,
            ),
            $expectsDelta && $status === Response::HTTP_GONE => CursorExpiredException::graph($safeBodyMessage),
            default => new RuntimeException(
                'GraphApiClient: '.$context.' returned HTTP '.$status.' — '.$safeBodyMessage,
            ),
        };
    }

    // Parses the Retry-After header into a seconds value. Graph
    // documents it as delta-seconds, but the broader HTTP spec also
    // allows an HTTP-date, converted against the injected Clock; falls
    // back to a 60-second default when missing or unparseable.
    private function parseRetryAfter(string $header): int
    {
        $trimmed = trim($header);

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $seconds = (int) $trimmed;

            return $seconds > 0 ? $seconds : 60;
        }

        // Non-numeric (or empty): read it as an HTTP-date against the clock,
        // and fall back to 60s whenever that is absent, unparseable, or past.
        $when = $trimmed === '' ? false : strtotime($trimmed);
        $delta = $when === false ? 0 : $when - $this->clock->now()->getTimestamp();

        return $delta > 0 ? $delta : 60;
    }

    // Extracts error.message from a Graph error body without ever
    // including request headers or the bearer token; caps the message
    // so a verbose IdP error cannot contaminate logging above.
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
        $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        // Graph puts the human-readable text under error.message, and a
        // machine code under error.code; prefer the former, accept the
        // latter, and treat anything else as an unrecognised body.
        $message = is_array($err)
            ? self::firstNonEmptyString($err['message'] ?? null, $err['code'] ?? null)
            : null;

        return $message === null ? self::UNRECOGNISED_ERROR_BODY : $this->safeMessage($message);
    }

    // Returns the first argument that is a non-empty string, or null when
    // none is — used to prefer a provider's message over its bare code.
    private static function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    // Caps the surfaced message and strips newlines so a verbose
    // provider error cannot contaminate a flash payload or log line;
    // delegates to the shared utility so the cap stays consistent
    // across the module's error-forwarding surfaces.
    private function safeMessage(string $raw): string
    {
        return SafeMessage::cap($raw);
    }

    // SSRF defence: refuses to attach a bearer token to any URL whose
    // scheme isn't https or whose host isn't on the allow-list. Fires
    // for both the first-page URL and the nextLink/deltaLink
    // pagination URLs Graph returns verbatim (the load-bearing case).
    private function assertAllowedUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new UnsafeProviderRequestException(
                'GraphApiClient: refusing to send bearer token over non-HTTPS scheme.',
            );
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array(strtolower($host), self::ALLOWED_HOSTS, strict: true)) {
            throw new UnsafeProviderRequestException(
                'GraphApiClient: refusing to send bearer token to non-Graph host: '
                .(is_string($host) && $host !== '' ? $host : '(unparseable)'),
            );
        }
    }

    // Walks the value array of a Graph response body, narrowing each
    // entry to array<string, mixed> and passing it through
    // normaliseMessageMeta; non-array entries are skipped.
    /**
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

    // Normalises a Graph message object into the shape BackfillInboxJob
    // consumes; FakeGraphApiClient already returns this exact shape,
    // so the two paths stay type-identical at the call site.
    /**
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

    // Returns a non-expired access token, refreshing via the OAuth
    // provider when the cached token is missing or within 60 seconds
    // of its stamped expiry; Microsoft rotates refresh tokens
    // single-use, so the fresh one is persisted on every refresh.
    private function ensureFreshAccessToken(int $inboxId): string
    {
        $creds = $this->secrets->loadInbox($inboxId);
        if ($creds === null) {
            throw new InboxNotConfiguredException(
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
            try {
                $fresh = $this->oauth->refreshAccessToken($creds->refreshToken);
            } catch (InvalidGrantException $e) {
                // MicrosoftOAuthProvider maps invalid_grant/consent_required
                // responses into this typed sentinel before the league
                // client's raw exception reaches us, so non-OAuth
                // failures keep their original exception class.
                throw $this->raiseReconsentRequired($inboxId, $e);
            }
            // Persists the rotated pair via the repository's atomic
            // rotateRefreshToken hook — the underlying writeAtomic
            // makes the rotation crash-safe.
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

    // Dispatches InboxTokenFailed so the SystemAlertsBanner can surface
    // a re-consent prompt, then returns a typed
    // ReconsentRequiredException for the caller to throw.
    private function raiseReconsentRequired(int $inboxId, InvalidGrantException $cause): ReconsentRequiredException
    {
        $userId = $this->lookupInboxUserId($inboxId);
        $this->events->dispatch(new InboxTokenFailed(
            inboxId: $inboxId,
            userId: $userId,
            provider: 'microsoft',
        ));

        return new ReconsentRequiredException(
            inboxId: $inboxId,
            userId: $userId,
            provider: 'microsoft',
            previous: $cause,
        );
    }

    // Resolves the owning user_id for an inbox row. Returns 0 when
    // the row is missing or the column is non-integer, so the
    // caller's error-recovery path survives even if the inbox record
    // was deleted between the scan kick-off and the failed refresh.
    private function lookupInboxUserId(int $inboxId): int
    {
        $value = $this->db->connection()
            ->table('inboxes')
            ->where('id', $inboxId)
            ->value('user_id');

        return is_numeric($value) ? (int) $value : 0;
    }

    // This called itself a seam "a future test subclass could override", but
    // the class is final, so that subclass could never exist and the boundary
    // stayed untestable. Injected instead: null builds the production client,
    // a test passes one backed by a mock handler.
    private function makeHttpClient(): GuzzleClient
    {
        return $this->httpClient ?? new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }
}
