<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Navigation\Destination;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Reports\Internal\Dto\ReportDefinition;

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
        $params['amount_dir'] = $definition->amountDirection !== AmountDirection::Both->value ? $definition->amountDirection : null;

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return Destination::Transactions->urlFrom($this->urls, $params);
    }
}
