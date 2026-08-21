<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportDefinition;

final class DrilldownUrlBuilder
{
    public function __construct(private readonly UrlGenerator $urls) {}

    public function build(string $dimension, int|string|null $groupKey, Period $period, ReportDefinition $definition): string
    {
        $params = [];

        if ($groupKey !== null) {
            // time_bucket, and anything unrecognised, carries no id to filter by.
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
        $params['amount_dir'] = $definition->amountDirection !== 'both' ? $definition->amountDirection : null;

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $this->urls->route('transactions.index', $params);
    }
}
