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
use Modules\EmailScan\Internal\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Internal\Exceptions\ProviderTransportException;
use Modules\EmailScan\Internal\Exceptions\UnsafeProviderRequestException;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\ReconsentRequiredException;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/**
 * @link ../../../../.docs/features/email-scan/provider-transport-hardening.md
 */
final class GraphApiClient implements GraphApiClientContract
{
    private const GRAPH_BASE_URI = 'https://graph.microsoft.com/v1.0/';

    // Regional clouds (graph.microsoft.de, graph.microsoft.us) are
    // deliberately excluded — adding one is a reviewed config change.
    /** @var list<string> */
    private const ALLOWED_HOSTS = ['graph.microsoft.com'];

    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly MicrosoftOAuthProvider $oauth,
        private readonly Clock $clock,
        private readonly EventsDispatcher $events,
        private readonly DatabaseManager $db,
        private readonly GraphErrorMapper $errorMapper,
        private readonly ?GuzzleClient $httpClient = null,
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
        if ($senderPatterns === []) {
            return ['messages' => [], 'nextLink' => null];
        }

        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        if ($nextLink !== null && $nextLink !== '') {
            // Graph bakes the skip token and the original filter into
            // nextLink; rebuilding the query would lose the opaque token.
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

    // /messages/{id}/$value returns raw RFC 822 bytes directly — no
    // base64/JSON envelope, unlike Gmail's users.messages.get.
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        if (preg_match(self::MESSAGE_ID_PATTERN, $providerMessageId) !== 1) {
            throw new UnsafeProviderRequestException(
                'GraphApiClient: provider message id failed allow-list validation.',
            );
        }

        $url = self::GRAPH_BASE_URI.'me/messages/'.$providerMessageId.'/$value';

        // Redundant by construction (constant base + regex-validated
        // id), kept so every bearer-attaching path clears the same gate.
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
            throw $this->errorMapper->mapErrorResponse($e->getResponse(), 'GET /me/messages/{id}/$value');
        } catch (GuzzleException $e) {
            throw new ProviderTransportException(
                'GraphApiClient: HTTP error fetching raw message — '.$this->errorMapper->safeMessage($e->getMessage()),
            );
        }

        return (string) $response->getBody();
    }

    /**
     * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
     */
    public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
    {
        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = $this->makeHttpClient();

        if ($deltaLink !== null && $deltaLink !== '') {
            // $sinceOverride is ignored here: the delta cursor URL
            // already has its receivedDateTime filter baked in.
            $url = $deltaLink;
            $body = $this->getJson($client, $accessToken, $url, [], expectsDelta: true);
        } else {
            // The caller's pinned anchor wins: without one, messages arriving
            // during a multi-hour backfill slip past both filters.
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

    // Graph rejects $filter contains(subject, ...) on the messages
    // collection, so $search is the only keyword match available — and
    // Graph rejects $orderby alongside $search, hence neither is sent.
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
            $body = $this->getJson($client, $accessToken, $nextLink);
        } else {
            $quotedKeywords = array_map(
                static fn (string $k): string => '"'.str_replace('"', '\\"', $k).'"',
                $keywords,
            );
            // Graph's $search contract wants the whole KQL expression
            // wrapped in its own outer pair of double quotes.
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

        // Graph rejects a "not from/..." predicate alongside $search,
        // so the exclude list can only be applied client-side.
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

    // A message with no readable from-address is kept: the exclude
    // list can only ever remove an already-known sender.
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

    // OData escapes a single quote inside a string literal by doubling
    // it, so o'brien has to go out as o''brien.
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
        // The guard sits at the HTTP boundary, not the parse site: a malformed
        // @odata.nextLink/@odata.deltaLink is followed verbatim.
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
            throw $this->errorMapper->mapErrorResponse($e->getResponse(), 'GET '.$url, $expectsDelta);
        } catch (GuzzleException $e) {
            throw new ProviderTransportException(
                'GraphApiClient: HTTP error against '.$url.' — '.$this->errorMapper->safeMessage($e->getMessage()),
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

        // json_decode yields array<mixed, mixed>; casting each key is what
        // lets static analysis narrow the result to array<string, mixed>.
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

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

    // FakeGraphApiClient returns this exact shape, so the live and
    // fixture paths stay type-identical at the call site.
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

    // Microsoft rotates refresh tokens single-use, so the returned one must
    // be persisted on every refresh or the next refresh is rejected.
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
                throw $this->raiseReconsentRequired($inboxId, $e);
            }
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

    private function raiseReconsentRequired(int $inboxId, InvalidGrantException $cause): ReconsentRequiredException
    {
        $userId = $this->lookupInboxUserId($inboxId);
        $this->events->dispatch(new InboxTokenFailed(
            inboxId: $inboxId,
            userId: $userId,
            provider: MailProvider::Microsoft->value,
        ));

        return new ReconsentRequiredException(
            inboxId: $inboxId,
            userId: $userId,
            provider: MailProvider::Microsoft->value,
            previous: $cause,
        );
    }

    // Returns 0 rather than throwing: the inbox can be deleted between scan
    // kick-off and a failed refresh, and recovery still has to complete.
    private function lookupInboxUserId(int $inboxId): int
    {
        $value = $this->db->connection()
            ->table('inboxes')
            ->where('id', $inboxId)
            ->value('user_id');

        return is_numeric($value) ? (int) $value : 0;
    }

    private function makeHttpClient(): GuzzleClient
    {
        return $this->httpClient ?? new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
            // assertAllowedUrl runs before the bearer is attached, so a
            // followed 3xx would carry the token to another host past it.
            'allow_redirects' => false,
        ]);
    }
}
