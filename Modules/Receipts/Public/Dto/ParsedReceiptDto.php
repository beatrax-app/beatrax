<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

// The shape every matcher emits on a successful parse. ownIban carries
// the synthetic per-provider IBAN literal (PAYPAL/ICS-CARD/GOOGLE-PLAY);
// chainHints carries optional structured cross-source clues a matcher
// found in the body (the common case is an empty array).
final class ParsedReceiptDto extends Data
{
    /**
     * @param  array<int|string, mixed>  $rawPayload
     * @param  list<object>  $chainHints
     */
    public function __construct(
        public readonly string $merchantName,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $settledAmountMinor,
        public readonly ?string $settledCurrency,
        public readonly ?string $referenceId,
        public readonly CarbonImmutable $bookedAt,
        public readonly string $ownIban,
        public readonly ?string $description,
        public readonly array $rawPayload,
        public readonly array $chainHints = [],
    ) {}
}
