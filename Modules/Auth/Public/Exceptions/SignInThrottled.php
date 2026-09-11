<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Exceptions;

use RuntimeException;

// Raised instead of answering the credential, so the screen can say the reader
// is waiting rather than that they are wrong. A failed sign-in returns false;
// this is the one outcome that is neither a pass nor a mismatch.
final class SignInThrottled extends RuntimeException
{
    public function __construct(public readonly int $secondsRemaining)
    {
        parent::__construct('Sign-in is throttled for another '.$secondsRemaining.' seconds.');
    }
}
