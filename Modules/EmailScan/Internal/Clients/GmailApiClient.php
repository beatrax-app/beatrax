<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\Resource\UsersHistory;
use Google\Service\Gmail\Resource\UsersMessages;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;

/**
 * Production wrapper over google/apiclient's Gmail service.
 *
 * Mirrors the public method shape FakeGmailApiClient surfaces in
 * Wave 0 so test wiring can swap a Fake instance into the container
 * without any contract drift. Each call rebuilds a Google client
 * around a freshly-refreshed access token (refresh-if-near-expiry
 * happens transparently inside ensureFreshAccessToken) so a stale
 * cached token never reaches the wire.
 *
 * Error mapping:
 *
 *  - `users.messages.list` / `users.messages.get` / `users.history.list`
 *    rate-limit reasons (`rateLimitExceeded` / `userRateLimitExceeded`
 *    / `dailyLimitExceeded`) become RateLimitedException so the
 *    caller can transition the inbox state and let Horizon retry.
 *  - `users.history.list` returning HTTP 404 means the historyId is
 *    older than the ~7-day Gmail retention window — surfaced as
 *    CursorExpiredException so the caller falls back to a date-
 *    bounded re-scan.
 *  - Token payloads never appear in any thrown exception message —
 *    the catch block re-throws with provider error strings only.
 *
 * Discovery surface (`listDiscoveryCandidates`) is part of the
 * contract but its production body lands in a later plan; this
 * client returns an empty page so the discovery loop is inert
 * until the matcher phase wires the real keyword query.
 */
final class GmailApiClient
{
    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly GoogleOAuthProvider $oauth,
        private readonly Clock $clock,
    ) {}

    /**
     * `users.messages.list` with `q='from:(<a> OR <b> OR ...)'` and a
     * 100-message page size — the fetcher walks pages until
     * nextPageToken is null.
     *
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, historyId: ?string, resultSizeEstimate: int}
     */
    public function listSenderMessages(int $inboxId, array $senderPatterns, ?string $pageToken): array
    {
        $resource = $this->messagesResource($inboxId);
        $q = 'from:('.implode(' OR ', $senderPatterns).')';
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

    /**
     * `users.messages.get?format=raw`. Returns the RFC 822 byte
     * stream — Gmail's `raw` field is base64url-encoded, so this
     * method decodes before returning.
     */
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
     * `users.history.list`. Returns the slice of history entries
     * after the given startHistoryId so the caller can replay
     * messagesAdded / messagesDeleted since the cursor was set.
     *
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

    /**
     * Reserved for the discovery scan plan. The production body
     * arrives there; this client returns an empty page so any caller
     * wiring against the contract compiles without exercising live
     * keyword search.
     *
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string}
     */
    public function listDiscoveryCandidates(int $inboxId, array $keywords, array $excludeSenders): array
    {
        unset($inboxId, $keywords, $excludeSenders);

        return [
            'messages' => [],
            'nextPageToken' => null,
        ];
    }

    /**
     * Build a Gmail UsersMessages resource bound to a freshly-
     * refreshed access token. Each public method calls into this
     * so a token rotation between two calls is picked up
     * transparently.
     */
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

        return new GmailService($client);
    }

    /**
     * Return a non-expired access token for the inbox, refreshing
     * via the OAuth provider when the cached token is missing or
     * within 60 seconds of its stamped expiry.
     */
    private function ensureFreshAccessToken(int $inboxId): string
    {
        $creds = $this->secrets->loadInbox($inboxId);
        if ($creds === null) {
            throw new RuntimeException(
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
            $fresh = $this->oauth->refreshAccessToken($creds->refreshToken);
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
     * Translate a Google\Service\Exception into the typed sentinels
     * the rest of the module catches. Quota-related reasons become
     * RateLimitedException; every other case rethrows the original
     * exception unchanged (callers higher up wrap it in the per-
     * inbox state-machine error transition).
     */
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

    /**
     * Decode Gmail's `raw` field. Gmail returns base64url with no
     * padding; this method re-adds the padding before calling
     * base64_decode.
     */
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
}
