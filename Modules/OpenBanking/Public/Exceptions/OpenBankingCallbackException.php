<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use Modules\Core\Public\Support\Lang;
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
        return new self(Lang::get('openbanking::messages.errors.no_authorization_code'));
    }

    public static function wizardIncomplete(): self
    {
        return new self(Lang::get('openbanking::messages.errors.wizard_incomplete'));
    }

    public static function noSessionId(): self
    {
        return new self(Lang::get('openbanking::messages.errors.no_session_id'));
    }
}
