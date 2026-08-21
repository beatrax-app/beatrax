<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Reports\Internal\Aggregation\SpendQueryFilters;
use Modules\Reports\Internal\Enums\ReportCurrencyMode;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Internal\Http\ReportDefinitionRequestFactory;

// The builder's rail now spells these values in exactly one place each. Every
// one of them is also a `#[Url]` query value and a key inside a stored
// saved_reports.definition blob, so renaming a case would re-point every
// bookmarked report link and mis-read every definition already on disk.

it('pins the period preset values the ?period= param and stored definitions carry', function (): void {
    expect(array_column(ReportPeriodPreset::cases(), 'value'))->toBe([
        'this_month',
        'last_3_months',
        'last_6_months',
        'last_12_months',
        'ytd',
        'this_year',
        'custom',
    ]);
});

it('pins the currency mode, visualization and granularity values', function (): void {
    expect(array_column(ReportCurrencyMode::cases(), 'value'))->toBe(['base', 'original'])
        ->and(array_column(ReportViz::cases(), 'value'))->toBe(['table', 'bar', 'line', 'donut'])
        ->and(array_column(ReportGranularity::cases(), 'value'))->toBe(['monthly', 'weekly']);
});

it('pins the amount direction values Search, Reports and the transactions list share', function (): void {
    expect(array_column(AmountDirection::cases(), 'value'))->toBe(['in', 'out', 'both'])
        ->and((new SpendQueryFilters)->amountDirection)->toBe('both');
});

// An empty ?…= query string must land on the same values the #[Url] `except:`
// arguments omit, or the export route would compose a different report than
// the builder the reader exported from.
it('falls back to the values the builder omits from its own URL', function (): void {
    $definition = app(ReportDefinitionRequestFactory::class)->fromExportQuery(new Request);

    expect($definition->periodPreset)->toBe('this_month')
        ->and($definition->currencyMode)->toBe('base')
        ->and($definition->viz)->toBe('table')
        ->and($definition->granularity)->toBe(ReportGranularity::Monthly)
        ->and($definition->amountDirection)->toBe('both');
});
