<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class CardStatementForecastTile extends Data
{
    public function __construct(
        public readonly Money $amount,
        public readonly CarbonImmutable $dueDate,
        public readonly int $statementId,
        public readonly string $state,
    ) {}
}
