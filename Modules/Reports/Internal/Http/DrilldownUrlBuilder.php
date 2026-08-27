<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Navigation\Destination;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Reports\Internal\Aggregation\ReportMetric;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportMetricSelection;

final class DrilldownUrlBuilder
{
    public function __construct(private readonly UrlGenerator $urls) {}

    // $period is the window the CLICKED ROW covers, not the report's — for a
    // time bucket or a net-worth point that is the row's own bucket. Handing it
    // the whole range pointed three monthly rows at one identical list.
    public function build(string $dimension, int|string|null $groupKey, Period $period, ReportDefinition $definition): string
    {
        $params = [];

        if ($groupKey !== null) {
            $groupParams = match ($dimension) {
                'category' => ['category' => [(int) $groupKey]],
                'account' => ['account' => [(int) $groupKey]],
                'counterparty' => ['counterparty' => [(int) $groupKey]],
                default => [],
            };
            $params = [...$params, ...$groupParams];
        }

        // endExclusive is exclusive by contract but the "before" query
        // param is inclusive — this is the one place that conversion happens.
        $params['after'] = $period->start->toDateString();
        $params['before'] = $period->endExclusive->subDay()->toDateString();
        $params['amount_min'] = $definition->amountMin;
        $params['amount_max'] = $definition->amountMax;
        $params['amount_dir'] = self::direction($definition);
        $params['type'] = self::types($definition);

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return Destination::Transactions->urlFrom($this->urls, $params);
    }

    // A `spend` row that links to a list carrying salary income cannot be
    // reconciled against the figure it was clicked from, so the metric's own
    // direction narrows the list. An explicit direction on the definition is
    // the reader's, and stays.
    // The direction alone does not reconstruct the figure: a fee and a transfer
    // out are both negative and neither is counted as spend, so the list a
    // drill-down opens has to be narrowed to the same types the metric summed.
    /**
     * @return list<string>|null
     */
    private static function types(ReportDefinition $definition): ?array
    {
        $metric = ReportMetric::tryFrom($definition->metric);

        if ($metric === null) {
            return null;
        }

        $types = $metric->types();

        return $types === [] ? null : $types;
    }

    private static function direction(ReportDefinition $definition): ?string
    {
        if ($definition->amountDirection !== AmountDirection::Both->value) {
            return $definition->amountDirection;
        }

        return match ($definition->metric) {
            ReportMetricSelection::Spend->value => AmountDirection::Out->value,
            ReportMetricSelection::Income->value => AmountDirection::In->value,
            default => null,
        };
    }
}
