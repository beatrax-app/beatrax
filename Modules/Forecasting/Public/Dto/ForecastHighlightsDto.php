<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Modules\Chains\Public\Dto\NextSettlementDto;
use Spatie\LaravelData\Data;

/**
 * Dashboard "Forecast highlights" tile read payload.
 *
 * Carries the lowest-projected-balance line + the count of active
 * shortfall windows that fall inside the next 30 days. The
 * `nextIcsSettlement` field subsumes Phase 5's separate "Next ICS
 * settlement" tile by including the funder-aware
 * `NextSettlementDto`; the tile is therefore a strict superset of the
 * legacy dashboard tile path.
 *
 * Every minor-unit field is signed. Null lowest-projected-balance
 * indicates no projection exists for the user yet (fresh install).
 */
final class ForecastHighlightsDto extends Data
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $lowestProjectedBalanceMinor,
        public readonly ?string $lowestProjectedBalanceDate,
        public readonly ?int $lowestProjectedAccountId,
        public readonly ?string $lowestProjectedAccountName,
        public readonly int $activeShortfallCount,
        public readonly ?NextSettlementDto $nextIcsSettlement,
    ) {}
}
