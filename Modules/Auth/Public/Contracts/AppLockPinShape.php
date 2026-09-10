<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

// What a PIN is, in one place. The unlock screen is a numeric keypad with no
// letter key, so anything this does not admit is a PIN the reader could never
// type back — a permanent lockout reached through supported UI. Public because
// the pad that has to accept every PIN this admits is a screen in Mobile.
final class AppLockPinShape
{
    public const int MINIMUM_LENGTH = 6;

    public const int MAXIMUM_LENGTH = 10;

    public static function isWellFormed(string $pin): bool
    {
        return preg_match('/^[0-9]{'.self::MINIMUM_LENGTH.','.self::MAXIMUM_LENGTH.'}$/', $pin) === 1;
    }

    // Told apart from the rest so the reader who typed four digits is not
    // asked to re-read a rule about the alphabet.
    public static function isTooShort(string $pin): bool
    {
        return mb_strlen($pin) < self::MINIMUM_LENGTH;
    }
}
