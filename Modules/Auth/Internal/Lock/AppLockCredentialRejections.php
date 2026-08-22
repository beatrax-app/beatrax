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
    private const int MINIMUM_PIN_LENGTH = 6;

    public function __construct(private Hasher $hasher) {}

    public function pinRequired(string $pin): ?string
    {
        return $pin === '' ? Lang::get('auth::app_lock.error_pin_required') : null;
    }

    public function newPin(string $newPin, string $confirmPin): ?string
    {
        return match (true) {
            strlen($newPin) < self::MINIMUM_PIN_LENGTH => Lang::get('auth::app_lock.error_pin_too_short'),
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
