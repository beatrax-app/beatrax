<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One detector's verdict for a single account. Returned by every
 * `DetectsStartingBalance::detect()` call that recognised an opening
 * balance for the supplied import-run ids.
 *
 * The aggregator groups candidates by `accountId`, then picks the
 * winner per account: earliest `openingBalanceDate` wins; on a date
 * tie the canonical CAMT.053 source is preferred over MT940; if two
 * candidates still tie on both date and source, both are surfaced so
 * the wizard renders the conflict-resolution card variant.
 *
 * `openingBalanceMinor` is stored as a signed integer in the
 * account's `default_currency` minor units (cents for EUR). The
 * value is whatever the source statement reported — negative for
 * overdrawn accounts, positive for the typical case.
 *
 * `openingBalanceDate` is an ISO date string (`YYYY-MM-DD`). The
 * source `statement_summaries.opening_balance_date` column carries a
 * datetime but the detector strips the time component at the
 * boundary so the wizard renders a date-only chip and the
 * `accounts.starting_balance_date` write target (a `date` column)
 * round-trips cleanly.
 *
 * `sourceFormat` is the canonical source-format identifier of the
 * detector that emitted the candidate (`camt053`, `mt940`,
 * `ics-pdf`, `paypal-csv`). Used by the aggregator's tie-break
 * rules and by the wizard's per-source labelling.
 */
final class StartingBalanceCandidate extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $openingBalanceMinor,
        public readonly string $openingBalanceDate,
        public readonly string $sourceFormat,
    ) {}
}
