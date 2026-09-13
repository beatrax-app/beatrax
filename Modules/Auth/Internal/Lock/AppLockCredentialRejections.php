<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Public\Contracts\AppLockPinShape;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;

// One owner for what the app lock says back to a reader. Is the PIN there, do
// the two new PINs agree, is this the account password, and why was a PIN just
// refused: four questions, six settings actions and both lock screens asking
// them, and a vocabulary spread that far is one a later edit half-changes.
final readonly class AppLockCredentialRejections
{
    public function __construct(
        private Hasher $hasher,
        private PinVerificationService $verifier,
        private Clock $clock,
    ) {}

    // A metered refusal is not always a wrong PIN: inside the backoff window
    // the verifier answers before it looks at the code, so a correct one lands
    // here too and must not be told it was wrong. Every screen that checks a
    // PIN meters it, so every one of them owes the reader these three answers.
    /**
     * @link ../../../../.docs/features/auth/every-pin-check-is-metered.md
     */
    public function refusedPin(int $userId): string
    {
        $lockedUntil = $this->verifier->lockedUntil($userId);
        $remaining = $this->verifier->remainingAttempts($userId);

        return match (true) {
            $lockedUntil !== null => Lang::get('auth::lock_screen.error_backoff', ['wait' => $this->secondsUntil($lockedUntil).'s']),
            $remaining !== null => Lang::choice('auth::lock_screen.error_incorrect_remaining', $remaining),
            default => Lang::get('auth::lock_screen.error_incorrect'),
        };
    }

    // Rounded up, and never to nought: a window with a fraction of a second
    // left is still shut, and "try again in 0s" reads as a screen that has
    // stopped counting.
    private function secondsUntil(CarbonImmutable $lockedUntil): int
    {
        return max(1, (int) ceil($this->clock->now()->diffInMilliseconds($lockedUntil, absolute: true) / 1000));
    }

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
            ! AppLockPinShape::isWellFormed($newPin) => Lang::get('auth::app_lock.error_pin_digits', [
                'min' => AppLockPinShape::MINIMUM_LENGTH,
                'max' => AppLockPinShape::MAXIMUM_LENGTH,
            ]),
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
