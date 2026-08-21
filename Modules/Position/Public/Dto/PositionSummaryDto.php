<?php

declare(strict_types=1);

namespace Modules\Position\Public\Dto;

use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Spatie\LaravelData\Data;

final class PositionSummaryDto extends Data
{
    /**
     * @param  ?array<int, PerCurrencyTile>  $tilesByCurrency
     * @param  array<int, RecurringSeriesDto>  $upcoming
     * @param  array<int, BudgetProgressRow>  $budgets
     */
    public function __construct(
        public readonly DashboardSummary $summary,
        public readonly ?array $tilesByCurrency,
        public readonly ?EmailScanHealthTile $emailScanHealth,
        public readonly array $upcoming,
        public readonly array $budgets,
        public readonly bool $shortfallAhead,
    ) {}
}
