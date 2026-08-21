<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PaymentType;
use Spatie\LaravelData\Data;

// confidence runs 0..100: 90+ for an authoritative source signal, 70-90 for
// a description-keyword match, 40 for the universal fallback. `sourceHint`
// is for structured logs only and must never reach the UI.
final class PaymentTypeHint extends Data
{
    public function __construct(
        public readonly PaymentType $type,
        public readonly int $confidence,
        public readonly string $sourceHint,
    ) {}
}
