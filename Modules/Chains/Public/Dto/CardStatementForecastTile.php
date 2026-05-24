<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Payload for the dashboard "Next ICS settlement" tile.
 *
 * `$amount` is denominated in the card_statement's display currency
 * (typically EUR for the beatrax ICS account). `$dueDate` is computed
 * as `period_end + 5 calendar days`; the constant lag is this wave's
 * forecast and a future plan refines it via the user's prior cadence.
 *
 * The dashboard hides the tile when this DTO is null (no open
 * card_statement exists). The DTO never serialises a placeholder
 * value — empty becomes "no tile".
 */
final class CardStatementForecastTile extends Data
{
    public function __construct(
        public readonly Money $amount,
        public readonly CarbonImmutable $dueDate,
        public readonly int $statementId,
        public readonly string $state,
    ) {}
}
