<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Only the codes the code itself names — the EUR fallback plus the majors FX
// and import reference as literals. The selectable display set is data-driven.
enum Currency: string
{
    case Eur = 'EUR';

    case Usd = 'USD';

    case Gbp = 'GBP';

    case Jpy = 'JPY';
}
