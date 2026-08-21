<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// The column stays a string; this enum is the one canonical spelling callers
// map through.
enum CardStatementCreditReason: string
{
    case Overpayment = 'overpayment';

    case RefundAfterClose = 'refund_after_close';
}
