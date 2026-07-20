<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @link ../../../../.docs/features/import/architecture.md#key-services--events
 */
interface PaymentTypeHinter
{
    // Pure function of its input — no DB reads, no per-call state,
    // bound as a singleton. `null` means "no signal for this row";
    // the classifier stage then consults the next tagged hinter.
    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint;
}
