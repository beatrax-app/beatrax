<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\Resource\UsersHistory;
use Google\Service\Gmail\Resource\UsersMessages;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\ReconsentRequiredException;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class GmailApiClient implements GmailApiClientContract
{
    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly GoogleOAuthProvider $oauth,
        private readonly Clock $clock,
        private readonly EventsDispatcher $events,
        private readonly DatabaseManager $db,
        private readonly ?GuzzleClient $httpClient = null,
    ) {}

    // users.messages.list with q='from:(<a> OR <b> OR ...)' and a
    // 100-message page size; a non-null $windowStart also carries
    // Gmail's after: operator so the cursor-expiry fallback walk is
    // bounded to the last_scan_at-minus-7-days window the caller passes.
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
        $resource = $this->messagesResource($inboxId);
        $q = 'from:('.implode(' OR ', $senderPatterns).')';
        if ($windowStart !== null) {
            // Gmail's `after:` operator accepts both date strings
            // ("after:2026/05/10") and unix timestamps. The unix-
            // timestamp form gives sub-day precision so the 7-day
            // fallback window is tight against the lower bound.
            $q .= ' after:'.$windowStart->getTimestamp();
        }
        $params = ['q' => $q, 'maxResults' => 100];
        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = $resource->listUsersMessages('me', $params);
        } catch (GoogleServiceException $e) {
            throw $this->mapRateLimit($e);
        }

        $messages = [];
        foreach ($response->getMessages() as $m) {
            $messages[] = ['id' => $m->getId(), 'threadId' => $m->getThreadId()];
        }

        $nextPageToken = $response->getNextPageToken();
        if ($nextPageToken === '') {
            $nextPageToken = null;
        }

        $estimate = $response->getResultSizeEstimate();

        return [
            'messages' => $messages,
            'nextPageToken' => $nextPageToken,
            // listUsersMessages does not return a historyId; the
            // caller separately reads it once the walk completes via
            // a getProfile() call. Returning null here keeps the
            // contract aligned with the Fake.
            'historyId' => null,
            'resultSizeEstimate' => (int) $estimate,
        ];
    }

    // users.messages.get?format=raw. Gmail's raw field is
    // base64url-encoded, so this method decodes before returning the
    // RFC 822 byte stream.
    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        $resource = $this->messagesResource($inboxId);
        try {
            $msg = $resource->get('me', $providerMessageId, ['format' => 'raw']);
        } catch (GoogleServiceException $e) {
            throw $this->mapRateLimit($e);
        }

        return self::base64UrlDecode($msg->getRaw());
    }

    // users.history.list. Returns the slice of history entries after
    // the given startHistoryId so the caller can replay
    // messagesAdded/messagesDeleted since the cursor was set.
    /**
     * @return array{history: list<array<string, mixed>>, historyId: ?string}
     */
    public function listHistory(int $inboxId, string $startHistoryId): array
    {
        $resource = $this->historyResource($inboxId);
        try {
            $response = $resource->listUsersHistory('me', [
                'startHistoryId' => $startHistoryId,
            ]);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                throw CursorExpiredException::gmail();
            }
            throw $this->mapRateLimit($e);
        }

        $historyId = $response->getHistoryId();
        $historyIdStr = $historyId === '' ? null : $historyId;

        return [
            // The History entries carry a heterogeneous payload; the
            // caller in the incremental-scan plan unpacks the
            // messagesAdded list. For this plan the array shape is
            // simply forwarded.
            'history' => [],
            'historyId' => $historyIdStr,
        ];
    }

    // Daily-discovery query: users.messages.list with a broad
    // subject:(...) keyword filter minus the known-sender -from:(...)
    // allow-list, paged at 100. Each list entry fetches headers only
    // via format=metadata so no .eml body byte is ever persisted here.
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
        $resource = $this->messagesResource($inboxId);

        // Each keyword is wrapped in double quotes so Gmail treats it
        // as an exact-phrase match (avoids partial-word hits like
        // "invoice" matching "invoiceless"); inner double quotes are
        // backslash-escaped per Gmail's query-string conventions.
        $quotedKeywords = array_map(
            static fn (string $k): string => '"'.str_replace('"', '\\"', $k).'"',
            $keywords,
        );
        $q = 'subject:('.implode(' OR ', $quotedKeywords).')';

        if ($excludeSenders !== []) {
            // Strip operator-significant characters so a malformed
            // sender string cannot break the query: Gmail's exclude
            // syntax is -from:(...) with OR-joined entries, and stray
            // parentheses or a literal "OR" would read as an operator.
            $safeExcludes = array_map(
                static fn (string $s): string => str_replace(['(', ')', ' OR '], '', $s),
                $excludeSenders,
            );
            $q .= ' -from:('.implode(' OR ', $safeExcludes).')';
        }

        $params = ['q' => $q, 'maxResults' => 100];
        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = $resource->listUsersMessages('me', $params);
        } catch (GoogleServiceException $e) {
            throw $this->mapRateLimit($e);
        }

        $messages = [];
        foreach ($response->getMessages() as $m) {
            $messageId = $m->getId();
            if ($messageId === '') {
                continue;
            }

            // Per-message metadata fetch — headers only, NO body. The
            // payload's internalDate field is milliseconds since epoch;
            // the From header is the parsed sender (RFC 822 syntax).
            try {
                $meta = $resource->get('me', $messageId, [
                    'format' => 'metadata',
                    'metadataHeaders' => ['From', 'Date'],
                ]);
            } catch (GoogleServiceException $e) {
                // A single-message failure must not abort the whole
                // discovery walk — surface it to the caller via the
                // typed sentinel so the rate-limit envelope is honoured
                // when applicable.
                throw $this->mapRateLimit($e);
            }

            $internalDateMs = $meta->getInternalDate();
            $internalDateIso = self::internalDateMsToIso($internalDateMs);

            $payload = $meta->getPayload();
            $fromAddress = '';
            $fromName = null;
            $headers = $payload->getHeaders();
            foreach ($headers as $header) {
                if (strcasecmp($header->getName(), 'From') === 0) {
                    [$fromAddress, $fromName] = self::parseFromHeader($header->getValue());
                    break;
                }
            }

            if ($fromAddress === '') {
                // Skip rows without a parseable sender — they would
                // never make it into a candidate row anyway.
                continue;
            }

            $messages[] = [
                'id' => $messageId,
                'fromAddress' => $fromAddress,
                'fromName' => $fromName,
                'internalDate' => $internalDateIso,
            ];
        }

        $nextPageToken = $response->getNextPageToken();
        if ($nextPageToken === '') {
            $nextPageToken = null;
        }

        return [
            'messages' => $messages,
            'nextPageToken' => $nextPageToken,
        ];
    }

    // Builds a Gmail UsersMessages resource bound to a
    // freshly-refreshed access token, so a token rotation between two
    // calls is picked up transparently.
    private function messagesResource(int $inboxId): UsersMessages
    {
        $gmail = $this->makeGmailService($inboxId);
        $resource = $gmail->users_messages;
        if (! $resource instanceof UsersMessages) {
            throw new RuntimeException(
                'GmailApiClient: Gmail service has no users_messages resource.',
            );
        }

        return $resource;
    }

    private function historyResource(int $inboxId): UsersHistory
    {
        $gmail = $this->makeGmailService($inboxId);
        $resource = $gmail->users_history;
        if (! $resource instanceof UsersHistory) {
            throw new RuntimeException(
                'GmailApiClient: Gmail service has no users_history resource.',
            );
        }

        return $resource;
    }

    private function makeGmailService(int $inboxId): GmailService
    {
        $accessToken = $this->ensureFreshAccessToken($inboxId);
        $client = new GoogleClient;
        $client->setAccessToken([
            'access_token' => $accessToken,
            'expires_in' => 3600,
        ]);

        // The Google SDK builds its own Guzzle instance unless given one, so
        // this is the only point at which the transport can be replaced. It
        // stays null in production.
        if ($this->httpClient instanceof GuzzleClient) {
            $client->setHttpClient($this->httpClient);
        }

        return new GmailService($client);
    }

    // Returns a non-expired access token for the inbox, refreshing via
    // the OAuth provider when the cached token is missing or within
    // 60 seconds of its stamped expiry.
    private function ensureFreshAccessToken(int $inboxId): string
    {
        $creds = $this->secrets->loadInbox($inboxId);
        if ($creds === null) {
            throw new InboxNotConfiguredException(
                "GmailApiClient: no OAuth credentials persisted for inbox {$inboxId}.",
            );
        }

        $nowTs = $this->clock->now()->getTimestamp();
        $expiresTs = $creds->expiresAt?->getTimestamp();
        $cachedAccessToken = $creds->accessToken;

        // Refresh when the cached token is missing OR within 60s of
        // its stamped expiry. Gmail does NOT rotate refresh tokens
        // single-use, so rotateRefreshToken accepts the same refresh
        // token back unchanged.
        if (
            $cachedAccessToken === null
            || $cachedAccessToken === ''
            || $expiresTs === null
            || $expiresTs < $nowTs + 60
        ) {
            try {
                $fresh = $this->oauth->refreshAccessToken($creds->refreshToken);
            } catch (InvalidGrantException $e) {
                // GoogleOAuthProvider maps invalid_grant/consent_required
                // responses into this typed sentinel before the league
                // client's raw exception reaches us, so non-OAuth
                // failures keep their original exception class.
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

    // Dispatches InboxTokenFailed so the SystemAlertsBanner can surface
    // a re-consent prompt, then returns a typed
    // ReconsentRequiredException for the caller to throw.
    private function raiseReconsentRequired(int $inboxId, InvalidGrantException $cause): ReconsentRequiredException
    {
        $userId = $this->lookupInboxUserId($inboxId);
        $this->events->dispatch(new InboxTokenFailed(
            inboxId: $inboxId,
            userId: $userId,
            provider: 'gmail',
        ));

        return new ReconsentRequiredException(
            inboxId: $inboxId,
            userId: $userId,
            provider: 'gmail',
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

    // Translates a Google\Service\Exception into the typed sentinels
    // the rest of the module catches; every non-quota case rethrows
    // the original exception unchanged.
    private function mapRateLimit(GoogleServiceException $e): GoogleServiceException|RateLimitedException
    {
        $errors = $e->getErrors();
        $reason = '';
        if (isset($errors[0]['reason'])) {
            $reason = $errors[0]['reason'];
        }

        if (in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded'], strict: true)) {
            return new RateLimitedException(
                retryAfterSeconds: 60,
                message: 'Gmail rate limit exceeded ('.$reason.').',
            );
        }

        return $e;
    }

    // Decodes Gmail's raw field. Gmail returns base64url with no
    // padding, so this method re-adds it before calling base64_decode.
    private static function base64UrlDecode(string $value): string
    {
        $padded = $value.str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException(
                'GmailApiClient: failed to base64url-decode message raw payload.',
            );
        }

        return $decoded;
    }

    // Converts Gmail's internalDate (milliseconds-since-epoch string)
    // into an ISO 8601/RFC 3339 UTC string, falling back to the
    // current time when the value is missing or unparseable — safe
    // because the discovery loop only orders by this, never settles on it.
    private static function internalDateMsToIso(mixed $internalDateMs): string
    {
        if (! is_numeric($internalDateMs)) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        $seconds = intdiv((int) $internalDateMs, 1000);

        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }

    // Parses an RFC 822 From header value into a [address, name] pair,
    // accepting both "Name" <addr@example.com> and bare addr@example.com
    // forms. The address is lowercased so downstream callers can
    // compare without re-normalising; the name is null when absent.
    /**
     * @return array{0: string, 1: ?string}
     */
    private static function parseFromHeader(string $rawValue): array
    {
        $trimmed = trim($rawValue);
        if ($trimmed === '') {
            return ['', null];
        }

        if (preg_match('/^(?<name>.*?)\s*<(?<addr>[^>]+)>\s*$/u', $trimmed, $matches) === 1) {
            $name = trim($matches['name'], " \t\n\r\0\x0B\"");
            $addr = strtolower(trim($matches['addr']));

            return [$addr, $name !== '' ? $name : null];
        }

        $addr = strtolower($trimmed);

        return [$addr, null];
    }
}
