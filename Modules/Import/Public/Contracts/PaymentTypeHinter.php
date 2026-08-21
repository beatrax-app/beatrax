<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

interface PaymentTypeHinter
{
    // Pure and bound as a singleton. null means no signal for this row, and
    // the stage consults the next tagged hinter.
    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint;
}
