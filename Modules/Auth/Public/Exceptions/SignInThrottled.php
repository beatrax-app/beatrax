<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Exceptions;

use RuntimeException;

// Raised instead of answering the credential, so the screen can say the reader
// is waiting rather than that they are wrong. A failed sign-in returns false;
// this is the one outcome that is neither a pass nor a mismatch.
final class SignInThrottled extends RuntimeException
{
    // recoveryCodeRejected: a code was offered as the way past the meter and
    // did not open it. The meter stands either way — this is only which of the
    // two sentences the screen owes the reader.
    public function __construct(
        public readonly int $secondsRemaining,
        public readonly bool $recoveryCodeRejected = false,
    ) {
        parent::__construct('Sign-in is throttled for another '.$secondsRemaining.' seconds.');
    }
}
