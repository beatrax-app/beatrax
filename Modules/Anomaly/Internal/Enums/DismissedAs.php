<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Enums;

// Why a dismissed alert was dismissed. `expected` also wrote a suppression
// rule, so the two words are not interchangeable: one mutes the merchant and
// the other only closes the row. The column stays a string; this is the one
// spelling it maps through.
enum DismissedAs: string
{
    case Dismissed = 'dismissed';

    case Expected = 'expected';
}
