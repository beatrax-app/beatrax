<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Payload for chain-aware forecasting. `accountId` is the FUNDER ASN
 * account whose balance the projection deducts the settlement amount
 * from on `dueDate` — NOT the ICS card account id. The funder is
 * resolved at query time by `CardStatementQuery::nextSettlementForUser`
 * (see that method's doc for the lookup chain).
 *
 * Distinct from `CardStatementForecastTile` — that DTO carries only
 * the card-side amount + due date for the legacy dashboard tile.
 * `NextSettlementDto` is the strict superset for the chain-aware
 * forecasting surface; the two coexist until a later phase chooses to
 * consolidate.
 */
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
