<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// Why a card_statement_credits row exists: leftover `overpayment` surplus,
// or a `refund_after_close` landing after the statement settled. The column
// stays string; this enum is the one canonical spelling callers map through.
enum CardStatementCreditReason: string
{
    case Overpayment = 'overpayment';

    case RefundAfterClose = 'refund_after_close';
}
