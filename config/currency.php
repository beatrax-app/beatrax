<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;

return [
    // Only the fallback for a row that carries no currency of its own; the
    // user-selectable display set is data-driven.
    'base' => Currency::Eur->value,
];
