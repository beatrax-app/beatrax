<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\Resource\Users;
use Google\Service\Gmail\Resource\UsersHistory;
use Google\Service\Gmail\Resource\UsersMessages;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\EmailScan\Internal\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\ReconsentRequiredException;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpFoundation\Response;

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
        $resource = $this->messagesResource($inboxId);
        $q = 'from:('.implode(' OR ', $senderPatterns).')';
        if ($windowStart !== null) {
            // Gmail's `after:` takes a date string or a unix timestamp;
            // only the timestamp form has sub-day precision.
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
            'resultSizeEstimate' => (int) $estimate,
        ];
    }

    // users.getProfile is the only endpoint that hands back the mailbox's
    // current historyId; users.messages.list does not carry one.
    public function currentHistoryId(int $inboxId): ?string
    {
        $resource = $this->usersResource($inboxId);
        try {
            $profile = $resource->getProfile('me');
        } catch (GoogleServiceException $e) {
            throw $this->mapRateLimit($e);
        }

        $historyId = $profile->getHistoryId();

        return $historyId === '' ? null : $historyId;
    }

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
            if ($e->getCode() === Response::HTTP_NOT_FOUND) {
                throw CursorExpiredException::gmail();
            }
            throw $this->mapRateLimit($e);
        }

        $historyId = $response->getHistoryId();
        $historyIdStr = $historyId === '' ? null : $historyId;

        return [
            // Not yet unpacked: the History payload is heterogeneous
            // and only the historyId below is read back today.
            'history' => [],
            'historyId' => $historyIdStr,
        ];
    }

    // Discovery fetches headers only (format=metadata), so no .eml
    // body byte is ever read — let alone persisted — on this path.
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
        $q = self::buildDiscoveryQuery($keywords, $excludeSenders);

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
            $candidate = $messageId === '' ? null : $this->discoveryCandidate($resource, $messageId);
            if ($candidate !== null) {
                $messages[] = $candidate;
            }
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

    // Keywords are double-quoted for exact-phrase matching: unquoted,
    // "invoice" also matches "invoiceless".
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     */
    private static function buildDiscoveryQuery(array $keywords, array $excludeSenders): string
    {
        $quotedKeywords = array_map(
            static fn (string $k): string => '"'.str_replace('"', '\\"', $k).'"',
            $keywords,
        );
        $q = 'subject:('.implode(' OR ', $quotedKeywords).')';

        if ($excludeSenders === []) {
            return $q;
        }

        // Inside -from:(...) a stray parenthesis or a literal " OR " in
        // a sender string parses as an operator, not as text.
        $safeExcludes = array_map(
            static fn (string $s): string => str_replace(['(', ')', ' OR '], '', $s),
            $excludeSenders,
        );

        return $q.' -from:('.implode(' OR ', $safeExcludes).')';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function discoveryCandidate(UsersMessages $resource, string $messageId): ?array
    {
        try {
            $meta = $resource->get('me', $messageId, [
                'format' => 'metadata',
                'metadataHeaders' => ['From', 'Date'],
            ]);
        } catch (GoogleServiceException $e) {
            throw $this->mapRateLimit($e);
        }

        $fromAddress = '';
        $fromName = null;
        foreach ($meta->getPayload()->getHeaders() as $header) {
            if (strcasecmp($header->getName(), 'From') === 0) {
                [$fromAddress, $fromName] = self::parseFromHeader($header->getValue());
                break;
            }
        }

        if ($fromAddress === '') {
            return null;
        }

        return [
            'id' => $messageId,
            'fromAddress' => $fromAddress,
            'fromName' => $fromName,
            'internalDate' => self::internalDateMsToIso($meta->getInternalDate()),
        ];
    }

    private function messagesResource(int $inboxId): UsersMessages
    {
        $gmail = $this->makeGmailService($inboxId);
        $resource = $gmail->users_messages;
        if (! $resource instanceof UsersMessages) {
            throw new GmailResourceUnavailableException(
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
            throw new GmailResourceUnavailableException(
                'GmailApiClient: Gmail service has no users_history resource.',
            );
        }

        return $resource;
    }

    private function usersResource(int $inboxId): Users
    {
        $gmail = $this->makeGmailService($inboxId);
        $resource = $gmail->users;
        if (! $resource instanceof Users) {
            throw new GmailResourceUnavailableException(
                'GmailApiClient: Gmail service has no users resource.',
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
            'expires_in' => Duration::Hour->seconds(),
        ]);

        // The Google SDK builds its own Guzzle instance unless given
        // one, so this is the only seam a test can drive it through.
        if ($this->httpClient instanceof GuzzleClient) {
            $client->setHttpClient($this->httpClient);
        }

        return new GmailService($client);
    }

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

        // Unlike Microsoft, Gmail does not rotate refresh tokens
        // single-use, so the same one is written back unchanged.
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
            provider: MailProvider::Gmail->value,
        ));

        return new ReconsentRequiredException(
            inboxId: $inboxId,
            userId: $userId,
            provider: MailProvider::Gmail->value,
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

    // Gmail returns base64url with the padding stripped; base64_decode
    // needs it back.
    private static function base64UrlDecode(string $value): string
    {
        $padded = $value.str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new GmailRawDecodeException(
                'GmailApiClient: failed to base64url-decode message raw payload.',
            );
        }

        return $decoded;
    }

    // Gmail's internalDate is epoch milliseconds; falling back to now on a
    // bad value is safe because the discovery loop only orders by it.
    private static function internalDateMsToIso(mixed $internalDateMs): string
    {
        if (! is_numeric($internalDateMs)) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        $seconds = intdiv((int) $internalDateMs, 1000);

        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }

    // The address comes back lowercased so callers can compare it against
    // the sender allow-list without re-normalising.
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
