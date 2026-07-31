<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Http\Request;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class ReportDefinitionRequestFactory
{
    // The CSV export route builds its ReportDefinition straight from query
    // parameters. Keeping that coercion here leaves the route closure a
    // thin dispatcher rather than a deeply-nested per-parameter ternary
    // chain.
    public function fromExportQuery(Request $request): ReportDefinition
    {
        return new ReportDefinition(
            metric: $this->stringOr($request->query('metric'), 'spend'),
            dimension: $this->stringOr($request->query('dim'), 'category'),
            periodPreset: $this->stringOr($request->query('period'), 'this_month'),
            // tryFrom, not from: an unknown ?gran= is a bad link rather than
            // corrupt state, and it used to reach TimeBucketGenerator and
            // throw, so ?gran=nonsense was a 500. The stored-value rejection
            // C7-R21 asks for happens in ReportDefinition::from() instead.
            granularity: ReportGranularity::tryFrom($this->stringOr($request->query('gran'), ''))
                ?? ReportGranularity::default(),
            currencyMode: $this->stringOr($request->query('ccy'), 'base'),
            viz: $this->stringOr($request->query('viz'), 'table'),
            customFrom: $this->nullableString($request->query('from')),
            customTo: $this->nullableString($request->query('to')),
            compare: $request->boolean('cmp'),
            accounts: $this->toIntList($request->query('account', [])),
            categories: $this->toIntList($request->query('category', [])),
            counterparties: $this->toIntList($request->query('counterparty', [])),
            amountMin: $this->nullableString($request->query('amount_min')),
            amountMax: $this->nullableString($request->query('amount_max')),
            amountDirection: $this->stringOr($request->query('amount_dir'), 'both'),
        );
    }

    private function stringOr(mixed $value, string $fallback): string
    {
        return is_string($value) ? $value : $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<int>
     */
    private function toIntList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (int|float|string $id): int => (int) $id,
            array_filter($value, static fn (mixed $id): bool => is_numeric($id)),
        ));
    }
}
