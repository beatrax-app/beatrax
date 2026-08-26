<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;

return [
    // What an install ships with, for a reader who has never opened the
    // /settings base-currency picker; once they have, users.base_currency is
    // what every roll-up renders in. The selectable set is data-driven.
    'base' => Currency::Eur->value,
];
