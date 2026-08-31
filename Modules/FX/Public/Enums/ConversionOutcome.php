<?php

declare(strict_types=1);

namespace Modules\FX\Public\Enums;

// NoRate is kept apart from Passthrough because both leave the amount in its
// original currency. Read as one, a conversion that gave up looks like a figure
// that needed none, and the caller adds foreign minor units into a
// base-currency total.
enum ConversionOutcome: string
{
    case Passthrough = 'passthrough';

    case Converted = 'converted';

    case NoRate = 'no_rate';
}
