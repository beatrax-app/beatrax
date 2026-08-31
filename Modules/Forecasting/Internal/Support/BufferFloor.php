<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use Modules\Ledger\Public\Enums\AccountKind;

// The floor a projected balance is judged against, or none. The zero-crossing
// default is a statement about CASH: a card's balance is what is owed, so it
// sits below zero for the card's whole life and every day of the horizon came
// back a shortfall -- eight notices off one card on the shipped demo seed.
/**
 * @link ../../../../.docs/features/forecasting/architecture.md#shortfall-detection
 */
final class BufferFloor
{
    public const int ZERO_CROSSING = 0;

    // A buffer the reader set is honoured on any kind — asking to be told when
    // a card's debt passes a figure is a question this can answer.
    public static function forKind(?AccountKind $kind, ?int $explicitMinor): ?int
    {
        if ($explicitMinor !== null) {
            return $explicitMinor;
        }

        return $kind === AccountKind::IcsCard ? null : self::ZERO_CROSSING;
    }
}
