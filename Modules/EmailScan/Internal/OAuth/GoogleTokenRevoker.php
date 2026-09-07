<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Response;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class GoogleTokenRevoker
{
    private const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClient $http,
        private LoggerInterface $logger,
    ) {}

    // Best-effort: a failed revoke must not block the local disconnect. The
    // cost is that Google's server-side grant may outlive the deleted local
    // copy until it expires on its own.
    public function revoke(string $refreshToken): bool
    {
        if ($refreshToken === '') {
            return false;
        }

        try {
            $response = $this->http->createPendingRequest()
                ->timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->post(self::REVOKE_URL, ['token' => $refreshToken]);
        } catch (Throwable $e) {
            $this->logger->warning('GoogleTokenRevoker: revoke request failed.', SafeExceptionContext::describe($e));

            return false;
        }

        if ($response->successful() || self::grantWasAlreadyGone($response)) {
            return true;
        }

        // The one outcome that used to leave no trace at all. Best-effort is a
        // decision about not blocking the disconnect, not about not saying it
        // happened: the local copy is deleted straight after this, so nothing
        // will come back to retry, and the grant stays live until it expires.
        $this->logger->warning('GoogleTokenRevoker: the provider refused the revocation, so its copy of the grant outlives the local disconnect.', [
            'status' => $response->status(),
        ]);

        return false;
    }

    // A grant that is already expired or revoked from the account page comes
    // back 400 invalid_token. Nothing outlives the disconnect there, so it is
    // the outcome that was asked for rather than a refusal to give it.
    private static function grantWasAlreadyGone(Response $response): bool
    {
        return $response->status() === 400 && $response->json('error') === 'invalid_token';
    }
}
