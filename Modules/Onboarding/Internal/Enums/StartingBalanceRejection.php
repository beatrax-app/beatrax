<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Enums;

// Why a starting balance was refused, separated from the sentence that says
// so: the wizard's write path needs the verdict without a currency to format
// a range message with, and the card needs the sentence.
enum StartingBalanceRejection
{
    case AmountMissing;

    case AmountOutOfRange;

    case DateMissing;

    case DateUnreadable;

    case DateInFuture;
}
