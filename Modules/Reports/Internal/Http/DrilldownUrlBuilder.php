<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportDefinition;

/**
 * The single, tested `Period` → `SearchFilters`/URL conversion for chart
 * drill-down (Req 12 / D-03). Every chart partial's `dataPointSelection`
 * handler navigates to a URL this class built — never `/search` (that
 * route is commented out, 999.6-RESEARCH.md Pitfall 3) and never an inline
 * `subDay()`/`addDay()` conversion at a second call site (Pitfall 2b).
 *
 * `route('transactions.index', ...)` uses the SINGULAR array param names
 * `TransactionsList`'s `#[Url(as: 'account'/'category'/'counterparty', ...)]`
 * properties expect — `accounts`/`categories`/`counterparties` would
 * silently no-op.
 *
 * `$period->endExclusive` is exclusive by contract; `SearchFilters.before`
 * (and `TransactionsList`'s `before` query param) is inclusive — this is
 * the ONE place `before = $period->endExclusive->subDay()` happens
 * (Pitfall 2b). `time_bucket` carries no group filter param at all (a
 * time-bucket row has no category/account/counterparty id to filter by);
 * only `after`/`before` narrow the drilled-down list, and they reflect the
 * REPORT's own period — not a per-bucket sub-window, since a
 * `ReportResultRow` does not carry its own bucket boundaries.
 *
 * A `null` `groupKey` (the "No category" / "No counterparty" / "No
 * account" aggregation bucket every dimension query emits) omits the
 * dimension filter entirely rather than filtering on a synthetic id.
 */
final class DrilldownUrlBuilder
{
    public function __construct(private readonly UrlGenerator $urls) {}

    public function build(string $dimension, int|string|null $groupKey, Period $period, ReportDefinition $definition): string
    {
        $params = [];

        if ($groupKey !== null) {
            $groupParams = match ($dimension) {
                'category' => ['category' => [(int) $groupKey]],
                'account' => ['account' => [(int) $groupKey]],
                'counterparty' => ['counterparty' => [(int) $groupKey]],
                default => [], // time_bucket (and anything unrecognised): no group filter param
            };
            $params = [...$params, ...$groupParams];
        }

        $params['after'] = $period->start->toDateString();
        $params['before'] = $period->endExclusive->subDay()->toDateString();
        $params['amount_min'] = $definition->amountMin;
        $params['amount_max'] = $definition->amountMax;
        $params['amount_dir'] = $definition->amountDirection !== 'both' ? $definition->amountDirection : null;

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $this->urls->route('transactions.index', $params);
    }
}
