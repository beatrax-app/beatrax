<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\ReportDefinitionRequestFactory;

function rdrfFactory(): ReportDefinitionRequestFactory
{
    return app(ReportDefinitionRequestFactory::class);
}

it('falls back to the canonical defaults when no query parameters are present', function (): void {
    $definition = rdrfFactory()->fromExportQuery(Request::create('/reports/export', 'GET'));

    expect($definition->metric)->toBe('spend')
        ->and($definition->dimension)->toBe('category')
        ->and($definition->periodPreset)->toBe('this_month')
        ->and($definition->granularity)->toBe(ReportGranularity::default())
        ->and($definition->currencyMode)->toBe('base')
        ->and($definition->viz)->toBe('table')
        ->and($definition->customFrom)->toBeNull()
        ->and($definition->customTo)->toBeNull()
        ->and($definition->compare)->toBeFalse()
        ->and($definition->accounts)->toBe([])
        ->and($definition->categories)->toBe([])
        ->and($definition->counterparties)->toBe([])
        ->and($definition->amountMin)->toBeNull()
        ->and($definition->amountMax)->toBeNull()
        ->and($definition->amountDirection)->toBe('both');
});

it('coerces every provided query parameter, dropping non-numeric ids and empty amounts', function (): void {
    $request = Request::create('/reports/export', 'GET', [
        'metric' => 'income',
        'dim' => 'account',
        'period' => 'ytd',
        'gran' => 'weekly',
        'ccy' => 'original',
        'viz' => 'bar',
        'from' => '2026-01-01',
        'to' => '2026-03-31',
        'cmp' => '1',
        'account' => ['1', '2', 'not-a-number'],
        'category' => ['5'],
        'counterparty' => ['9'],
        'amount_min' => '10.00',
        'amount_max' => '',
        'amount_dir' => 'in',
    ]);

    $definition = rdrfFactory()->fromExportQuery($request);

    expect($definition->metric)->toBe('income')
        ->and($definition->dimension)->toBe('account')
        ->and($definition->periodPreset)->toBe('ytd')
        ->and($definition->granularity)->toBe(ReportGranularity::Weekly)
        ->and($definition->currencyMode)->toBe('original')
        ->and($definition->viz)->toBe('bar')
        ->and($definition->customFrom)->toBe('2026-01-01')
        ->and($definition->customTo)->toBe('2026-03-31')
        ->and($definition->compare)->toBeTrue()
        ->and($definition->accounts)->toBe([1, 2])
        ->and($definition->categories)->toBe([5])
        ->and($definition->counterparties)->toBe([9])
        ->and($definition->amountMin)->toBe('10.00')
        ->and($definition->amountMax)->toBeNull()
        ->and($definition->amountDirection)->toBe('in');
});

it('rejects an unknown granularity as a bad link, falling back to the default', function (): void {
    $request = Request::create('/reports/export', 'GET', ['gran' => 'fortnightly']);

    $definition = rdrfFactory()->fromExportQuery($request);

    expect($definition->granularity)->toBe(ReportGranularity::default());
});

it('treats a non-array id parameter as no restriction', function (): void {
    $request = Request::create('/reports/export', 'GET', ['account' => 'scalar-not-array']);

    $definition = rdrfFactory()->fromExportQuery($request);

    expect($definition->accounts)->toBe([]);
});
