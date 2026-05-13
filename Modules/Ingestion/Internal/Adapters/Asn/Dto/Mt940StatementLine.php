<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn\Dto;

use Carbon\CarbonImmutable;

/**
 * Decoded `:61:` statement-line tag — one bank-side movement on the
 * statement, parsed into typed fields. The `customerReference` field uses
 * the ASN-extended 34-character variant (not the SWIFT-standard 16).
 *
 * `status` follows the MT940 convention:
 *   - `C` = credit (positive amountMinor)
 *   - `D` = debit (negative amountMinor)
 *   - `RC` = reversal of credit (negative amountMinor)
 *   - `RD` = reversal of debit (positive amountMinor)
 *
 * Lives under `Internal/` because the shape is parser-implementation
 * detail; the adapter projects it onto `SourceTransactionDto`.
 */
final readonly class Mt940StatementLine
{
    public function __construct(
        public CarbonImmutable $valueDate,
        public ?CarbonImmutable $entryDate,
        public string $status,
        public ?string $transactionTypeCode,
        public int $amountMinor,
        public ?string $customerReference,
        public ?string $bankReference,
        public ?string $extraDetails,
    ) {}
}
