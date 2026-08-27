<?php

declare(strict_types=1);

namespace Modules\FX\Public\Enums;

// Why a rate refresh came back with nothing. The reader is shown the same
// sentence for all three, but the operator reading the log needs to tell an
// unreachable provider chain from a feed that answered with rates the range
// guard threw away.
enum FxRefreshFailureReason: string
{
    case AllProvidersFailed = 'all_providers_failed';

    case NoUsableRates = 'no_usable_rates';

    case Unexpected = 'unexpected';
}
