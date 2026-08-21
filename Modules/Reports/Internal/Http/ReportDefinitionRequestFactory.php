<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Http\Request;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

final class ReportDefinitionRequestFactory
{
    // Keeps the export route's per-parameter coercion out of the route closure.
    public function fromExportQuery(Request $request): ReportDefinition
    {
        return new ReportDefinition(
            metric: $this->stringOr($request->query('metric'), 'spend'),
            dimension: $this->stringOr($request->query('dim'), 'category'),
            periodPreset: $this->stringOr($request->query('period'), 'this_month'),
            // tryFrom, not from: an unknown ?gran= is a bad link, not corrupt
            // state, and it used to reach TimeBucketGenerator and 500. Rejecting
            // a bad STORED value is ReportDefinition::from()'s job instead.
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
