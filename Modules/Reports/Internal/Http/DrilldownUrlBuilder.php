<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Navigation\Destination;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Reports\Internal\Aggregation\ReportMetric;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportDimension;

final readonly class DrilldownUrlBuilder
{
    public function __construct(private UrlGenerator $urls) {}

    // $period is the window the CLICKED ROW covers, not the report's — for a
    // time bucket or a net-worth point that is the row's own bucket. Handing it
    // the whole range pointed three monthly rows at one identical list.
    public function build(string $dimension, int|string|null $groupKey, Period $period, ReportDefinition $definition): string
    {
        // The row's own group overwrites the filter it came from, never joins
        // it: the group key is already inside that filter, and a report narrowed
        // to one account opened a list carrying every account's rows.
        $params = [...self::filterParams($definition), ...self::groupParams($dimension, $groupKey)];

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
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        return Destination::Transactions->urlFrom($this->urls, $params);
    }

    /**
     * @return array<string, list<int>>
     */
    private static function filterParams(ReportDefinition $definition): array
    {
        return [
            'account' => $definition->accounts,
            'category' => $definition->categories,
            'counterparty' => $definition->counterparties,
        ];
    }

    // A null group key on the category dimension is the report's "Uncategorized"
    // bucket, which is a filter of its own -- emitting nothing for it opened the
    // whole period, 32 transactions under a row that said 85.00. The other two
    // dimensions have no such bucket to name, so they still emit nothing.
    /**
     * @return array<string, list<int>|string>
     */
    private static function groupParams(string $dimension, int|string|null $groupKey): array
    {
        $reportDimension = ReportDimension::tryFrom($dimension);

        // The empty `category` clears the inherited filter: "no category" and
        // "one of these categories" cannot both hold, and an AND of the two
        // opened an empty list under a row carrying money.
        if ($groupKey === null) {
            return $reportDimension === ReportDimension::Category ? ['uncategorized' => '1', 'category' => []] : [];
        }

        return match ($reportDimension) {
            ReportDimension::Category => ['category' => [(int) $groupKey]],
            ReportDimension::Account => ['account' => [(int) $groupKey]],
            ReportDimension::Counterparty => ['counterparty' => [(int) $groupKey]],
            default => [],
        };
    }

    // The type set is what the metric summed, so it carries the whole
    // narrowing on its own: a fee and a transfer out are both negative and
    // neither is spend.
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

    // Only the reader's own, never one derived from the metric. `spend` counts
    // a refund, which is positive, so an added `out` dropped from the list
    // exactly the rows the figure had already subtracted.
    private static function direction(ReportDefinition $definition): ?string
    {
        return $definition->amountDirection === AmountDirection::Both->value
            ? null
            : $definition->amountDirection;
    }
}
