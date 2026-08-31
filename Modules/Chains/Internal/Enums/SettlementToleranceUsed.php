<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Enums;

// The `evidence.tolerance_used` vocabulary. `Exceeded` is load-bearing beyond
// presentation: the chain_links NULL-endpoint trigger permits a NULL
// to_transaction_id only for an ics_bulk_settle candidate carrying this exact
// value, so its spelling is pinned by a migration.
enum SettlementToleranceUsed: string
{
    case AmountFloor = 'amount_5eur';

    case Percent = 'percent_2';

    case Exceeded = 'exceeded';

    case RefundAfterClose = 'refund_after_close';
}
