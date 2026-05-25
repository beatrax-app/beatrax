<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;
use Throwable;

/**
 * Sentinel raised when an OAuth token refresh fails in a way that
 * requires the user to re-grant consent at the provider (invalid_grant,
 * consent_required, 401 Unauthorized on refresh).
 *
 * The exception carries the typed (inboxId, userId, provider) triple so
 * a downstream listener — RaiseReconsentAlertOnTokenFailure — can write
 * a system_alerts row scoped to the correct user + inbox without
 * pulling secrets back off disk.
 *
 * The message body never includes the failed refresh token or any
 * provider-returned access-token payload; the caller mapping the
 * provider's response into this sentinel passes only the short
 * provider error string (`invalid_grant`, `consent_required`) and the
 * stable identifying tuple.
 */
final class ReconsentRequiredException extends RuntimeException
{
    public function __construct(
        public readonly int $inboxId,
        public readonly int $userId,
        public readonly string $provider,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('OAuth re-consent required for %s inbox %d.', $provider, $inboxId),
            0,
            $previous,
        );
    }
}
