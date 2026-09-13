<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Public\Exceptions\SignInThrottled;
use Modules\Core\Models\User;

// The way out of a spent sign-in meter that is not waiting for it. Anybody who
// can reach the sign-in screen can spend somebody else's, so the meter alone is
// a lockout a stranger walks a household into; a recovery code is the one
// credential that stranger does not hold.
final readonly class RecoveryCodeEscape
{
    public function __construct(
        private RecoveryAttemptThrottle $attempts,
        private RecoveryCodeAuthenticator $authenticator,
        private SignInThrottle $signIn,
    ) {}

    /**
     * @throws SignInThrottled where the meter still stands afterwards
     */
    public function clearOrRefuse(string $usernameInput, string $codeInput): void
    {
        if ($codeInput === '') {
            throw new SignInThrottled($this->signIn->availableIn($usernameInput));
        }

        // Its own cap, because the escape is a guessing surface like every
        // other guest credential: ten hashes a try, against a sheet of ten.
        if ($this->attempts->isExhausted($usernameInput)) {
            throw new SignInThrottled($this->attempts->availableIn($usernameInput));
        }

        $this->attempts->recordAttempt($usernameInput);

        // verify() spends the code, writes the audit row, equalises its hash
        // count and returns the same nothing for an unknown username as for a
        // wrong code. Everything this path owes enumeration is already in it.
        if (! $this->authenticator->verify($usernameInput, $codeInput) instanceof User) {
            throw new SignInThrottled($this->signIn->availableIn($usernameInput), recoveryCodeRejected: true);
        }

        $this->attempts->clear($usernameInput);
        $this->signIn->clear($usernameInput);
    }
}
