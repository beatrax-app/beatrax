<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Enums;

// Two chain steps write type='bank' for different things: the known-IBAN bridge
// for an institution the reader transacts through, the bank-fee corpus for a
// charge the bank levies. This is what tells the profile page which it has.
enum CounterpartySubcategory: string
{
    case Fee = 'fee';
}
