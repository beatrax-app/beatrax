<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking\Dto;

use Carbon\CarbonImmutable;

final readonly class Mt940StatementLine
{
    public function __construct(
        public CarbonImmutable $valueDate,
        public ?CarbonImmutable $entryDate,
        /** @var string one of C (credit, +amountMinor), D (debit, -amountMinor),
         *      RC (reversal of credit, -amountMinor), RD (reversal of debit, +amountMinor)
         */
        public string $status,
        public ?string $transactionTypeCode,
        public int $amountMinor,
        /** @var ?string extended 34-character variant, not the SWIFT-standard 16 */
        public ?string $customerReference,
        public ?string $bankReference,
        public ?string $extraDetails,
    ) {}
}
