<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PaymentType;
use Spatie\LaravelData\Data;

// `confidence` (0..100): 90+ for authoritative source signals (CAMT.053
// BkTxCd, PayPal event type), 70-90 for description-keyword matches,
// 40 for the universal fallback (wins only when every source-specific
// hinter declines). `sourceHint` is structured-log-only, never UI/PII.
final class PaymentTypeHint extends Data
{
    public function __construct(
        public readonly PaymentType $type,
        public readonly int $confidence,
        public readonly string $sourceHint,
    ) {}
}
