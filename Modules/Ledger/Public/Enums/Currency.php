<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// ISO 4217 codes the domain pins by name: the base fallback (EUR) plus the
// majors the FX and import paths reference as literals. The user-selectable
// display set is data-driven (see SettingsPage); this enum is only the
// canonical spelling for the codes the code itself names.
enum Currency: string
{
    case Eur = 'EUR';

    case Usd = 'USD';

    case Gbp = 'GBP';

    case Jpy = 'JPY';
}
