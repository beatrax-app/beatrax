<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// accountId is the funder ASN account whose balance the projection
// deducts the settlement amount from on dueDate — not the ICS card
// account id. Distinct from CardStatementForecastTile, which carries
// only the card-side amount + due date for the dashboard tile.
final class NextSettlementDto extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly Money $amount,
        public readonly CarbonImmutable $dueDate,
        public readonly int $statementId,
        public readonly string $state,
    ) {}
}
