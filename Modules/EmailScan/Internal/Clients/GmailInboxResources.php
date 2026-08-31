<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use Google\Client as GoogleClient;
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

// One authorized Gmail API resource per call, and the stored OAuth grant that
// has to be fresh before there can be one. Apart from the calls it serves
// because an expired grant is recovered here, or becomes the reconsent the
// reader is asked for, before any endpoint is reached at all.
final readonly class GmailInboxResources
{
    public function __construct(
        private OAuthSecretsRepository $secrets,
        private GoogleOAuthProvider $oauth,
        private Clock $clock,
        private EventsDispatcher $events,
        private DatabaseManager $db,
        private ?GuzzleClient $httpClient = null,
    ) {}

    public function messages(int $inboxId): UsersMessages
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

    public function history(int $inboxId): UsersHistory
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

    public function users(int $inboxId): Users
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
}
