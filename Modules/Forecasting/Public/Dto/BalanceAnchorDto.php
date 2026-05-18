<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Per-account opening-balance anchor for a projection run, returned
 * by Wave 2's `BalanceAnchorResolver`.
 *
 * `source` records which input the anchor was derived from:
 *
 *   - `asn_statement_summary` — Phase 1 statement-summary closing line
 *   - `ics_card_statement` — Phase 5 card_statements open_balance
 *   - `user_input_opening_balance` — `accounts.opening_balance_minor`
 *     supplied by the user via the /settings editor
 *   - `sum_of_transactions` — projector-derived running balance when
 *     no anchor of higher fidelity is available
 *
 * Tracking the source lets the audit ribbon explain to the user where
 * the projection's starting point came from.
 */
final class BalanceAnchorDto extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $openingBalanceMinor,
        public readonly string $currency,
        public readonly CarbonImmutable $asOfDate,
        public readonly string $source,
    ) {}
}
