<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// accountId is the funder ASN account the projection deducts from on
// dueDate, not the ICS card account being settled.
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
