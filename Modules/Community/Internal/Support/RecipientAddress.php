<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Support;

// The single address a pre-filled cancellation mail may go to. RFC 6068
// separates recipients with a comma and `%2C` reaches the mail client as one,
// so this is an allow-list of what a real address needs rather than a deny-list
// of the separators known today.
final class RecipientAddress
{
    private const string ALLOWED = '/^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$/';

    public const int MAX_LENGTH = 255;

    public static function isSingle(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_LENGTH
            && preg_match(self::ALLOWED, $value) === 1
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
