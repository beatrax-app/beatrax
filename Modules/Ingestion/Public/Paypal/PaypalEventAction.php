<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Paypal;

// What the rollup walker does with a PayPal CSV row. A child enriches the
// parent its Reference Txn ID names; a parent owns a canonical transaction and
// so must carry a TransactionType.
enum PaypalEventAction: string
{
    case Parent = 'parent';

    case ChildFx = 'child-fx';

    case Skip = 'skip';

    public function isChild(): bool
    {
        return $this === self::ChildFx;
    }
}
