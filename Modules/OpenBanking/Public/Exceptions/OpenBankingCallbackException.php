<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use Modules\Core\Public\Support\Lang;
use RuntimeException;

// A callback that reached a valid OAuth state but could not become a stored
// connection. Each carries the reason the controller flashes, so one catch
// replaces every post-state return.
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
