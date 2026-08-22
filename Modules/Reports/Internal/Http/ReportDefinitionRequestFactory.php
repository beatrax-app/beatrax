<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http;

use Illuminate\Http\Request;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Support\ReportVocabulary;

final class ReportDefinitionRequestFactory
{
    public function fromExportQuery(Request $request): ReportDefinition
    {
        return new ReportDefinition(
            // tryFrom, not from: an unknown parameter is a bad link, not corrupt
            // state, and ?gran= used to reach TimeBucketGenerator and 500. Rejecting
            // a bad STORED value is ReportDefinition::from()'s job instead.
            metric: ReportVocabulary::metric($this->nullableString($request->query('metric'))),
            dimension: ReportVocabulary::dimension($this->nullableString($request->query('dim'))),
            periodPreset: ReportVocabulary::periodPreset($this->nullableString($request->query('period'))),
            granularity: ReportVocabulary::granularity($this->nullableString($request->query('gran'))),
            currencyMode: ReportVocabulary::currencyMode($this->nullableString($request->query('ccy'))),
            viz: ReportVocabulary::viz($this->nullableString($request->query('viz'))),
            customFrom: $this->nullableString($request->query('from')),
            customTo: $this->nullableString($request->query('to')),
            compare: $request->boolean('cmp'),
            accounts: $this->toIntList($request->query('account', [])),
            categories: $this->toIntList($request->query('category', [])),
            counterparties: $this->toIntList($request->query('counterparty', [])),
            amountMin: $this->nullableString($request->query('amount_min')),
            amountMax: $this->nullableString($request->query('amount_max')),
            amountDirection: $this->stringOr($request->query('amount_dir'), AmountDirection::Both->value),
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
