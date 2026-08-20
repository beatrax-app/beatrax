<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use Illuminate\Http\Client\Factory as HttpClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class GoogleTokenRevoker
{
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
    ) {}

    // Best-effort: a failed revoke (offline, already revoked, network) must
    // not block the local disconnect that deletes the token regardless. A
    // failure here only means Google's server-side grant may outlive the
    // deleted local copy until it expires on its own.
    public function revoke(string $refreshToken): bool
    {
        if ($refreshToken === '') {
            return false;
        }

        try {
            return $this->http->createPendingRequest()
                ->timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->post(self::REVOKE_URL, ['token' => $refreshToken])
                ->successful();
        } catch (Throwable $e) {
            $this->logger->warning('GoogleTokenRevoker: revoke request failed.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
