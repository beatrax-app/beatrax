<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;

// A consent callback that reached a valid OAuth state but could not be turned
// into a stored connection: no authorization code, missing wizard credentials,
// or a session response carrying no session id. Each holds the reason the
// callback controller flashes, so one catch replaces the post-state returns.
/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingCallbackException extends RuntimeException
{
    public static function noAuthorizationCode(): self
    {
        return new self('Enable Banking callback returned no authorization code.');
    }

    public static function wizardIncomplete(): self
    {
        return new self('Finish the Open Banking setup wizard first.');
    }

    public static function noSessionId(): self
    {
        return new self('Enable Banking did not return a session id.');
    }
}
