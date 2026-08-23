<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Core\Public\Support\Lang;

// One owner for what the app-lock screen says back to a reader: the same three
// questions — is the PIN there, do the two new PINs agree, is this the account
// password — are asked by six actions on that one screen, and a vocabulary
// spread over six call sites is one a later edit can only half-change.
final readonly class AppLockCredentialRejections
{
    public function __construct(private Hasher $hasher) {}

    // Presence only, and deliberately not the shape: this is the PIN already
    // stored being offered as proof, and an install that predates the shape
    // rule holds one the rule would now refuse. Refusing it here would take
    // away the Change PIN route out of exactly that state.
    public function pinRequired(string $pin): ?string
    {
        return $pin === '' ? Lang::get('auth::app_lock.error_pin_required') : null;
    }

    // Shape before agreement: two boxes that agree on something the keypad
    // cannot type are still a lockout, so the rule about what a PIN is runs
    // first and reads from AppLockPinShape rather than restating it.
    public function newPin(string $newPin, string $confirmPin): ?string
    {
        return match (true) {
            AppLockPinShape::isTooShort($newPin) => Lang::get('auth::app_lock.error_pin_too_short'),
            ! AppLockPinShape::isWellFormed($newPin) => Lang::get('auth::app_lock.error_pin_digits'),
            $newPin !== $confirmPin => Lang::get('auth::app_lock.error_pin_mismatch'),
            default => null,
        };
    }

    // An empty box is not a wrong answer. Reported as an incorrect password it
    // sends the reader off to check a password manager, when what is wrong is
    // the field in front of them.
    public function accountPassword(string $submitted, string $passwordHash): ?string
    {
        return match (true) {
            $submitted === '' => Lang::get('auth::app_lock.error_account_password_required'),
            ! $this->hasher->check($submitted, $passwordHash) => Lang::get('auth::app_lock.error_account_password'),
            default => null,
        };
    }
}
