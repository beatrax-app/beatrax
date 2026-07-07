<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Http\Livewire\PinnedReportsRow;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\TogglePin;
use Modules\Reports\Public\Dto\ReportDefinition;

uses(RefreshDatabase::class);

/*
 * 999.6-10 Task 1 (Req 10, T-999.6-28/29) — the dashboard "pinned reports"
 * mini-card row: up to 3 chart-only mini cards built from the caller's own
 * pinned saved reports, user-scoped, rendering nothing when there are zero
 * pins, each linking to /reports?report={id}.
 */

function prrUser(string $prefix = 'prr'): User
{
    /** @var User */
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function prrDefinition(string $viz = 'table'): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: 'monthly',
        currencyMode: 'base',
        viz: $viz,
    );
}

it('renders nothing when the user has zero pins', function (): void {
    $user = prrUser();
    test()->actingAs($user);

    $html = Livewire::test(PinnedReportsRow::class)->html();

    expect($html)->not->toContain('pinned-report-chart');
    expect(trim(strip_tags($html)))->toBe('');
});

it('renders one chart-only mini card per pinned report, up to 3', function (): void {
    $user = prrUser();
    test()->actingAs($user);

    $names = ['Spend by category', 'Income this year', 'Net worth trend'];
    $reports = [];
    foreach ($names as $name) {
        $reports[] = app(SaveReport::class)->save($user, prrDefinition(), $name);
    }
    foreach ($reports as $report) {
        app(TogglePin::class)->toggle($user, $report->id);
    }

    $component = Livewire::test(PinnedReportsRow::class)
        ->assertSee('Spend by category')
        ->assertSee('Income this year')
        ->assertSee('Net worth trend');

    $chartCount = substr_count($component->html(), 'data-testid="pinned-report-chart"');
    expect($chartCount)->toBe(3);
});

it('caps at 3 mini cards even if a 4th pinned row somehow exists (defense in depth)', function (): void {
    $user = prrUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 4; $i++) {
        $reports[] = app(SaveReport::class)->save($user, prrDefinition(), "Report {$i}");
    }
    foreach (array_slice($reports, 0, 3) as $report) {
        app(TogglePin::class)->toggle($user, $report->id);
    }

    // Simulate a data anomaly bypassing TogglePin's own 3-pin cap (T-999.6-21)
    // — the query's independent LIMIT 3 (T-999.6-29) must still hold, since
    // this row's dashboard rendering must never trust a single enforcement
    // point.
    $reports[3]->update(['pinned' => true, 'pin_order' => 4]);

    $chartCount = substr_count(Livewire::test(PinnedReportsRow::class)->html(), 'data-testid="pinned-report-chart"');
    expect($chartCount)->toBe(3);
});

it('cards are user-scoped and never render another user\'s pinned report', function (): void {
    $owner = prrUser('prr-owner');
    test()->actingAs($owner);
    $saved = app(SaveReport::class)->save($owner, prrDefinition(), 'Owner Only Report');
    app(TogglePin::class)->toggle($owner, $saved->id);

    $other = prrUser('prr-other');
    test()->actingAs($other);

    $html = Livewire::test(PinnedReportsRow::class)->html();

    expect($html)->not->toContain('Owner Only Report');
});

it('cards link to the full report at /reports?report={id}', function (): void {
    $user = prrUser();
    test()->actingAs($user);

    $saved = app(SaveReport::class)->save($user, prrDefinition(), 'Linked Report');
    app(TogglePin::class)->toggle($user, $saved->id);

    Livewire::test(PinnedReportsRow::class)
        ->assertSeeHtml(route('reports.index', ['report' => $saved->id]));
});

it('mounts the chart using the shared beatraxApplyChartTheme pattern, chart-only (no table/rail)', function (): void {
    $user = prrUser();
    test()->actingAs($user);

    $saved = app(SaveReport::class)->save($user, prrDefinition(), 'Chart Mount Report');
    app(TogglePin::class)->toggle($user, $saved->id);

    $html = Livewire::test(PinnedReportsRow::class)->html();

    expect($html)->toContain('window.beatraxApplyChartTheme');
    expect($html)->not->toContain('<table');
});
