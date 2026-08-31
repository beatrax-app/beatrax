<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use Illuminate\Http\Client\Factory as HttpClient;
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
            return $this->http->createPendingRequest()
                ->timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->post(self::REVOKE_URL, ['token' => $refreshToken])
                ->successful();
        } catch (Throwable $e) {
            $this->logger->warning('GoogleTokenRevoker: revoke request failed.', SafeExceptionContext::describe($e));

            return false;
        }
    }
}
