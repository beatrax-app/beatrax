<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail\History;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\Resource\UsersHistory;
use Google\Service\Gmail\Resource\UsersMessages;
use Modules\Core\Public\Support\BoundedRead;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\UploadLimits;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\SafeMessage;
use Symfony\Component\HttpFoundation\Response;

final readonly class GmailApiClient implements GmailApiClientContract
{
    // A mailbox that has moved a very long way since the stored cursor can
    // paginate history further than one tick should hold in memory; the
    // watermark returned with a capped walk lets the next tick carry on.
    private const int HISTORY_PAGE_CAP = 25;

    /** @var list<string> */
    private const array LEGACY_THROTTLING_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded'];

    /** @var list<string> */
    private const array THROTTLING_STATUSES = ['RESOURCE_EXHAUSTED', 'UNAVAILABLE'];

    // Gmail does not publish an exact q= ceiling; this sits well inside the
    // ~2KB a query string survives across Google's front ends.
    private const int MAX_DISCOVERY_QUERY_LENGTH = 1800;

    public function __construct(private GmailInboxResources $resources) {}

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
        $resource = $this->resources->messages($inboxId);
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
            throw $this->mapProviderFailure($e);
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
        $resource = $this->resources->users($inboxId);
        try {
            $profile = $resource->getProfile('me');
        } catch (GoogleServiceException $e) {
            throw $this->mapProviderFailure($e);
        }

        $historyId = $profile->getHistoryId();

        return $historyId === '' ? null : $historyId;
    }

    public function getRawMessage(int $inboxId, string $providerMessageId): string
    {
        $resource = $this->resources->messages($inboxId);
        try {
            $msg = $resource->get('me', $providerMessageId, ['format' => 'raw']);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === Response::HTTP_NOT_FOUND) {
                throw new MessageUnavailableException(
                    "GmailApiClient: message {$providerMessageId} is no longer available on inbox {$inboxId}.",
                    previous: $e,
                );
            }
            throw $this->mapProviderFailure($e);
        }

        // Decided from the resource's own numbers, before base64UrlDecode
        // makes three more copies of a body Gmail will carry up to 35 MB
        // encoded. sizeEstimate is the provider's word for it and the encoded
        // length is ours; the larger one is the one this device has to hold.
        $raw = $msg->getRaw();
        BoundedRead::refuseAbove(
            'Gmail message '.$providerMessageId,
            max($msg->getSizeEstimate(), strlen($raw)),
            UploadLimits::MAX_MESSAGE_BYTES,
        );

        return self::base64UrlDecode($raw);
    }

    /**
     * @return array{history: list<array<string, mixed>>, historyId: ?string}
     */
    public function listHistory(int $inboxId, string $startHistoryId): array
    {
        $resource = $this->resources->history($inboxId);

        $history = [];
        $mailboxHistoryId = null;
        $lastRecordId = null;
        $pageToken = null;
        $pagesRead = 0;

        do {
            $response = $this->historyPage($resource, $startHistoryId, $pageToken);

            foreach ($response->getHistory() as $record) {
                $history[] = self::historyRecord($record);
                $recordId = self::sdkString($record->getId());
                if ($recordId !== null) {
                    $lastRecordId = $recordId;
                }
            }

            $responseHistoryId = self::sdkString($response->getHistoryId());
            if ($responseHistoryId !== null) {
                $mailboxHistoryId = $responseHistoryId;
            }

            $pageToken = self::sdkString($response->getNextPageToken());
            $pagesRead++;
        } while ($pageToken !== null && $pagesRead < self::HISTORY_PAGE_CAP);

        // The last record consumed is the only watermark a capped walk can
        // resume from without loss. Where the cap was reached without one,
        // there is nothing to resume from and the mailbox's own historyId is
        // what keeps the cursor moving instead of stalling on it forever.
        $exhausted = $pageToken === null;

        return [
            'history' => $history,
            'historyId' => $exhausted || $lastRecordId === null ? $mailboxHistoryId : $lastRecordId,
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
        $resource = $this->resources->messages($inboxId);
        $q = self::buildDiscoveryQuery($keywords, $excludeSenders);

        $params = ['q' => $q, 'maxResults' => 100];
        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = $resource->listUsersMessages('me', $params);
        } catch (GoogleServiceException $e) {
            throw $this->mapProviderFailure($e);
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

        $fitted = self::excludesThatFit($q, $safeExcludes);

        return $fitted === [] ? $q : $q.' -from:('.implode(' OR ', $fitted).')';
    }

    // The exclude list grows by one entry for every sender ever promoted or
    // dismissed, and past Gmail's q= ceiling every discovery call 400s. The
    // overflow is safe to drop: DiscoveryScanJob re-applies the same exclude
    // list client-side before any upsert.
    /**
     * @param  list<string>  $safeExcludes
     * @return list<string>
     */
    private static function excludesThatFit(string $keywordClause, array $safeExcludes): array
    {
        $budget = self::MAX_DISCOVERY_QUERY_LENGTH - strlen($keywordClause) - strlen(' -from:()');
        $fitted = [];
        $used = 0;

        foreach ($safeExcludes as $exclude) {
            $cost = strlen($exclude) + ($fitted === [] ? 0 : strlen(' OR '));
            if ($used + $cost > $budget) {
                break;
            }
            $fitted[] = $exclude;
            $used += $cost;
        }

        return $fitted;
    }

    private function historyPage(UsersHistory $resource, string $startHistoryId, ?string $pageToken): ListHistoryResponse
    {
        // messageAdded only: labelAdded/labelRemoved/messageDeleted records
        // carry no id the fetcher could pull bytes for, and asking for them
        // spends pages of the walk on records that map to nothing.
        $params = [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => 'messageAdded',
        ];
        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        try {
            return $resource->listUsersHistory('me', $params);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === Response::HTTP_NOT_FOUND) {
                throw CursorExpiredException::gmail();
            }
            throw $this->mapProviderFailure($e);
        }
    }

    // The SDK declares these getters as string and returns null in the field, so
    // taking them as mixed is what makes the guard expressible: narrowing a value
    // the stub already calls a string reads as dead code and gets removed.
    private static function sdkString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function historyRecord(History $record): array
    {
        $added = [];
        foreach ($record->getMessagesAdded() as $messageAdded) {
            $message = $messageAdded->getMessage();
            $id = self::sdkString($message->getId());
            if ($id !== null) {
                $added[] = ['message' => ['id' => $id, 'threadId' => $message->getThreadId()]];
            }
        }

        return ['id' => $record->getId(), 'messagesAdded' => $added];
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
            throw $this->mapProviderFailure($e);
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

    // The one funnel every catch site throws through, so it decides for all of
    // them whether the queue should try again. A 401 arrives behind a refreshed
    // token, which makes it the provider refusing a credential no later attempt
    // repairs — left generic it was re-thrown and retried forever.
    private function mapProviderFailure(GoogleServiceException $e): GoogleServiceException|RateLimitedException|InvalidGrantException
    {
        $reason = self::throttlingReason($e);
        if ($reason !== null) {
            return new RateLimitedException(
                retryAfterSeconds: 60,
                message: 'Gmail rate limit exceeded ('.$reason.').',
            );
        }

        if ($e->getCode() === Response::HTTP_UNAUTHORIZED) {
            return new InvalidGrantException('Gmail rejected the access token: '.SafeMessage::cap($e->getMessage()));
        }

        return $e;
    }

    // Two shapes reach here: the legacy error.errors[].reason the SDK unpacks
    // into getErrors(), and the newer error.status Google returns without an
    // errors[] array at all — where getErrors() is null and the only signal
    // left is the body the exception message carries verbatim.
    private static function throttlingReason(GoogleServiceException $e): ?string
    {
        $errors = $e->getErrors();
        $legacy = $errors[0]['reason'] ?? null;
        if (is_string($legacy) && in_array($legacy, self::LEGACY_THROTTLING_REASONS, strict: true)) {
            return $legacy;
        }

        if ($e->getCode() === Response::HTTP_TOO_MANY_REQUESTS) {
            return 'HTTP '.Response::HTTP_TOO_MANY_REQUESTS;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($e->getMessage(), true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $status = is_array($error) ? ($error['status'] ?? null) : null;

        return is_string($status) && in_array($status, self::THROTTLING_STATUSES, strict: true)
            ? $status
            : null;
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
            return Instant::zulu(new DateTimeImmutable);
        }

        return Instant::zulu(new DateTimeImmutable('@'.intdiv((int) $internalDateMs, 1000)));
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
