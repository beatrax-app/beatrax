<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Deliberately decoupled from the full CanonicalTransaction DTO so the
// re-apply job can build one from a persisted transactions row without
// needing an import-pipeline DTO in scope — only the four properties a
// rule condition can ever reference are carried.
final readonly class RuleMatchInput
{
    public function __construct(
        public ?string $counterpartyName,
        public ?string $description,
        public int $settledAmountMinor,
        public CarbonImmutable $postedAt,
    ) {}

    public static function fromCanonical(CanonicalTransaction $tx): self
    {
        return new self(
            counterpartyName: $tx->counterpartyName,
            description: $tx->description,
            settledAmountMinor: $tx->settledAmountMinor,
            postedAt: $tx->postedAt,
        );
    }
}
