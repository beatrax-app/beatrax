<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\OAuth;

use Modules\Core\Public\Support\Lang;
use RuntimeException;

// A callback whose state never matched an issued one: a stale link, a second
// press of the back button, or a forged redirect. Named like
// OpenBankingCallbackException so the controller flashes a reason the reader
// can act on rather than the mechanism that produced it.
final class InvalidStateException extends RuntimeException
{
    public static function stateMismatch(): self
    {
        return new self(Lang::get('openbanking::messages.errors.oauth_state_mismatch'));
    }
}
