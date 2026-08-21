<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The tri-state amount filter Search, Reports and the transactions list all
// bind to the same `amount_dir` URL param. Distinct from Ledger\Direction,
// which is a transaction's own two-way money direction and has no "unset".
// The values are URL- and saved-report-persisted, so they cannot be renamed.
enum AmountDirection: string
{
    case In = 'in';

    case Out = 'out';

    case Both = 'both';
}
