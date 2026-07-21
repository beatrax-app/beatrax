<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// Payload for the dashboard "Next ICS settlement" tile; the dashboard
// hides the tile entirely when this DTO is null (no open card_statement)
// rather than serializing a placeholder. $dueDate = period_end + 5 days.
final class CardStatementForecastTile extends Data
{
    public function __construct(
        public readonly Money $amount,
        public readonly CarbonImmutable $dueDate,
        public readonly int $statementId,
        public readonly string $state,
    ) {}
}
