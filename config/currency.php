<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;

return [
    // The app-wide fallback currency, applied when a transaction or import
    // row carries no currency of its own. The user-selectable display set is
    // data-driven; this is only the default the domain reaches for.
    'base' => Currency::Eur->value,
];
