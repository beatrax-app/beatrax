<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\DrilldownUrlBuilder;

/**
 * @param  array{amountMin?: ?string, amountMax?: ?string, amountDirection?: string}  $overrides
 */
function dubDefinition(string $dimension, array $overrides = []): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: $dimension,
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-05-01',
        customTo: '2026-05-31',
        amountMin: $overrides['amountMin'] ?? null,
        amountMax: $overrides['amountMax'] ?? null,
        amountDirection: $overrides['amountDirection'] ?? 'both',
    );
}

function dubPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );
}

/**
 * @return array<string, mixed>
 */
function dubParseQuery(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);
    $parsed = [];
    parse_str(is_string($query) ? $query : '', $parsed);

    return $parsed;
}

it('drilldown_filter_mapping: category dimension maps groupKey to the singular category param', function (): void {
    $url = app(DrilldownUrlBuilder::class)->build('category', 42, dubPeriod(), dubDefinition('category'));
    $params = dubParseQuery($url);

    expect(parse_url($url, PHP_URL_PATH))->toBe('/transactions')
        ->and($params['category'] ?? null)->toBe(['42'])
        ->and($params)->not->toHaveKey('account')
        ->and($params)->not->toHaveKey('counterparty')
        ->and($params['after'])->toBe('2026-05-01')
        // Period.endExclusive is exclusive and SearchFilters' `before` is
        // inclusive, so the drilled-down `before` is one day earlier.
        ->and($params['before'])->toBe('2026-05-31');
});

it('drilldown_filter_mapping: account dimension maps groupKey to the singular account param', function (): void {
    $url = app(DrilldownUrlBuilder::class)->build('account', 7, dubPeriod(), dubDefinition('account'));
    $params = dubParseQuery($url);

    expect($params['account'] ?? null)->toBe(['7'])
        ->and($params)->not->toHaveKey('category')
        ->and($params)->not->toHaveKey('counterparty');
});

it('drilldown_filter_mapping: counterparty dimension maps groupKey to the singular counterparty param', function (): void {
    $url = app(DrilldownUrlBuilder::class)->build('counterparty', 99, dubPeriod(), dubDefinition('counterparty'));
    $params = dubParseQuery($url);

    expect($params['counterparty'] ?? null)->toBe(['99'])
        ->and($params)->not->toHaveKey('category')
        ->and($params)->not->toHaveKey('account');
});

it('drilldown_filter_mapping: time_bucket dimension carries no group param, only the boundary-converted after/before', function (): void {
    $url = app(DrilldownUrlBuilder::class)->build('time_bucket', '2026-05-01', dubPeriod(), dubDefinition('time_bucket'));
    $params = dubParseQuery($url);

    expect($params)->not->toHaveKey('category')
        ->and($params)->not->toHaveKey('account')
        ->and($params)->not->toHaveKey('counterparty')
        ->and($params['after'])->toBe('2026-05-01')
        ->and($params['before'])->toBe('2026-05-31');
});

it('drilldown_filter_mapping: a null groupKey on the category dimension names the uncategorized bucket', function (): void {
    $url = app(DrilldownUrlBuilder::class)->build('category', null, dubPeriod(), dubDefinition('category'));
    $params = dubParseQuery($url);

    // Emitting nothing at all opened the whole period: 32 transactions and
    // 2,459.11 out under a row that read 85.00. "No category" is a filter.
    expect($params)->not->toHaveKey('category')
        ->and($params['uncategorized'] ?? null)->toBe('1')
        ->and($params['after'])->toBe('2026-05-01');
});

it('drilldown_filter_mapping: a null groupKey on a dimension with no such bucket still emits nothing', function (string $dimension): void {
    $url = app(DrilldownUrlBuilder::class)->build($dimension, null, dubPeriod(), dubDefinition($dimension));
    $params = dubParseQuery($url);

    expect($params)->not->toHaveKey('uncategorized')
        ->and($params)->not->toHaveKey($dimension);
})->with(['account', 'counterparty', 'time_bucket']);

it('drilldown_filter_mapping: carries amount_min/amount_max/amount_dir through from the report definition', function (): void {
    $definition = dubDefinition('category', ['amountMin' => '10.00', 'amountMax' => '500.00', 'amountDirection' => 'out']);
    $url = app(DrilldownUrlBuilder::class)->build('category', 5, dubPeriod(), $definition);
    $params = dubParseQuery($url);

    expect($params['amount_min'])->toBe('10.00')
        ->and($params['amount_max'])->toBe('500.00')
        ->and($params['amount_dir'])->toBe('out');
});

it('drilldown_filter_mapping: omits amount_min/amount_max when the definition carries neither', function (): void {
    $url = app(DrilldownUrlBuilder::class)->build('category', 5, dubPeriod(), dubDefinition('category'));
    $params = dubParseQuery($url);

    expect($params)->not->toHaveKey('amount_min')
        ->and($params)->not->toHaveKey('amount_max');
});
