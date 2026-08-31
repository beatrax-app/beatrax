<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// A separate DTO from CanonicalTransaction so the re-apply job can build one
// from a persisted row without an import-pipeline DTO in scope.
final readonly class RuleMatchInput
{
    public function __construct(
        public ?string $counterpartyName,
        public ?string $description,
        public int $settledAmountMinor,
        public string $settledCurrency,
        public CarbonImmutable $postedAt,
    ) {}

    public static function fromCanonical(CanonicalTransaction $tx): self
    {
        return new self(
            counterpartyName: $tx->counterpartyName,
            description: $tx->description,
            settledAmountMinor: $tx->settledAmountMinor,
            settledCurrency: $tx->settledCurrency,
            postedAt: $tx->postedAt,
        );
    }
}
