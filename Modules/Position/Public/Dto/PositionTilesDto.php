<?php

declare(strict_types=1);

namespace Modules\Position\Public\Dto;

use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Spatie\LaravelData\Data;

// The three members of the position a dashboard render reads. The other four are
// each a child component's own question, asked again by that child with its own
// filter and its own client state.
final class PositionTilesDto extends Data
{
    /**
     * @param  ?array<int, PerCurrencyTile>  $tilesByCurrency
     */
    public function __construct(
        public readonly DashboardSummary $summary,
        public readonly ?array $tilesByCurrency,
        public readonly ?EmailScanHealthTile $emailScanHealth,
    ) {}
}
