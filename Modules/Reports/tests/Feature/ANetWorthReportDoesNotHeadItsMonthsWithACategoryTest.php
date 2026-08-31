<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Internal\Services\ReportCsvExporter;

uses(RefreshDatabase::class);

// net_worth is a balance series the aggregator answers on its own; the builder
// hides the dimension picker but the URL-bound property keeps its default, so
// the screen headed a column of months with "Category" while the CSV of the
// very same report said "Period".

function nwhUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nwh-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function nwhFirstColumnHeading(string $html): string
{
    preg_match('/<th\b[^>]*>(.*?)<\/th>/s', $html, $matches);

    return trim(strip_tags($matches[1] ?? ''));
}

it('heads the net-worth series with its period, not with the dimension the URL still carries', function (): void {
    $this->actingAs(nwhUser());

    $html = Livewire::test(ReportBuilder::class)
        ->set('metric', ReportMetricSelection::NetWorth->value)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-03-01')
        ->set('customTo', '2026-05-31')
        ->html();

    expect(nwhFirstColumnHeading($html))
        ->toBe(Lang::get('reports::builder.group_header.month'))
        ->not->toBe(Lang::get('reports::builder.group_header.category'));
});

it('gives the CSV of that same report the same first column the screen shows', function (): void {
    $user = nwhUser();

    $definition = new ReportDefinition(
        metric: ReportMetricSelection::NetWorth->value,
        // The dimension the builder's own #[Url] default leaves behind.
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-03-01',
        customTo: '2026-05-31',
    );

    $csv = app(ReportCsvExporter::class)->export($user, $definition);

    expect(explode(',', explode("\n", $csv)[0])[0])->toBe('Period');
});
