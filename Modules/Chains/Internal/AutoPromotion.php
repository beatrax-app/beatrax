<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

// Three same-signature confirmations promote every remaining candidate that
// shares the signature. ConfirmChainLink does the promoting and the review
// queue counts down to it; held apart, the countdown stopped matching when the
// promotion actually fired.
final class AutoPromotion
{
    public const int THRESHOLD = 3;

    public static function remaining(int $confirmedCount): int
    {
        return max(0, self::THRESHOLD - $confirmedCount);
    }
}
