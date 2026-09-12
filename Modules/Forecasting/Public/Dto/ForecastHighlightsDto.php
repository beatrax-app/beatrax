<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Modules\Chains\Public\Dto\NextSettlementDto;
use Modules\Forecasting\Public\Http\Livewire\ForecastHighlightsTile;
use Spatie\LaravelData\Data;

/**
 * @see ForecastHighlightsTile
 */
final class ForecastHighlightsDto extends Data
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $lowestProjectedBalanceMinor,
        public readonly ?string $lowestProjectedBalanceCurrency,
        public readonly ?string $lowestProjectedBalanceDate,
        public readonly ?int $lowestProjectedAccountId,
        public readonly ?string $lowestProjectedAccountName,
        public readonly int $activeShortfallCount,
        public readonly ?NextSettlementDto $nextIcsSettlement,
        public readonly bool $icsSettlementOverdue = false,
        // True while a projection run for the tile's horizon is pending or
        // running. Every other forecast member here was read off the run that
        // one supersedes, so the tile must not print them as current.
        public readonly bool $isComputing = false,
    ) {}
}
