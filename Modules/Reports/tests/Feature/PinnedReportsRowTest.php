<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Actions\TogglePin;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Public\Http\Livewire\PinnedReportsRow;

uses(RefreshDatabase::class);

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
        granularity: ReportGranularity::Monthly,
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

    // A fourth pin written past TogglePin's own cap: the query's independent
    // LIMIT 3 has to hold on its own, not trust the writer.
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

// The time-bucket query emits a row per bucket regardless, so a window opening
// before the first transaction starts with a run of flat zero periods.

function prrAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00PRR'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function prrExpense(DatabaseManager $db, User $user, Account $account, string $postedAt, int $settledMinor): void
{
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/prr-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'prr-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PRR Vendor',
        'counterparty_normalized' => 'prr-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'prr-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function prrChartOptions(string $html): array
{
    expect($html)->toMatch('/data-options="/');
    preg_match('/data-options="([^"]*)"/', $html, $matches);
    $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

it('drops the empty buckets before the first real one but keeps the empty ones between', function (): void {
    $user = prrUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = prrAccount($user);

    // Jan and Feb stay empty (the leading run), March spends, April stays
    // empty (a genuine zero month, in the middle of the data), May spends.
    prrExpense($db, $user, $account, '2026-03-10', -5_000);
    prrExpense($db, $user, $account, '2026-05-10', -3_000);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'time_bucket',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'line',
        customFrom: '2026-01-01',
        customTo: '2026-05-31',
    );

    $saved = app(SaveReport::class)->save($user, $definition, 'Leading zero series');
    app(TogglePin::class)->toggle($user, $saved->id);

    $options = prrChartOptions(Livewire::test(PinnedReportsRow::class)->html());

    // json_encode drops the zero fraction, so the wire values are ints.
    // 50 is March, 0 is the empty April kept in place, 30 is May.
    expect($options['series'][0]['data'])->toBe([50, 0, 30]);
    expect($options['xaxis']['categories'])->toHaveCount(3);
    expect($options['xaxis']['categories'][0])->toBe('Mar 2026');
});

// The mini card is ~267px wide, and ApexCharts trims every tick that will not
// fit: five months of history rendered as "Apr 2…", "May 2…", which reads as a
// day rather than a year. Whether the ticks then FIT is a browser measurement
// this test cannot make — it pins the option that stops the truncation, so the
// axis cannot silently go back to eliding.
it('tells ApexCharts not to truncate the axis ticks it draws', function (): void {
    $user = prrUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = prrAccount($user);

    prrExpense($db, $user, $account, '2026-04-10', -1_000);
    prrExpense($db, $user, $account, '2026-08-10', -2_000);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'time_bucket',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'bar',
        customFrom: '2026-04-01',
        customTo: '2026-08-31',
    );

    $saved = app(SaveReport::class)->save($user, $definition, 'Monthly net position');
    app(TogglePin::class)->toggle($user, $saved->id);

    $html = Livewire::test(PinnedReportsRow::class)->html();

    expect($html)->toContain('trim: false')
        ->toContain('hideOverlappingLabels: true');
});

function prrCategoryDefinition(string $viz): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: $viz,
        customFrom: '2026-03-01',
        customTo: '2026-03-31',
    );
}

// The donut's key is lifted out of the chart and rendered as card content, so
// the view has to recognise the chart type the component wrote.
it('renders the lifted-out legend for a pinned donut report', function (): void {
    $user = prrUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = prrAccount($user);

    prrExpense($db, $user, $account, '2026-03-10', -5_000);

    $saved = app(SaveReport::class)->save($user, prrCategoryDefinition(ReportViz::Donut->value), 'Spend split');
    app(TogglePin::class)->toggle($user, $saved->id);

    $html = Livewire::test(PinnedReportsRow::class)->html();

    expect(prrChartOptions($html)['chart']['type'])->toBe(ReportViz::Donut->value);
    expect($html)->toContain('data-testid="pinned-report-legend"');
});

it('renders no lifted-out legend for a pinned bar report', function (): void {
    $user = prrUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = prrAccount($user);

    prrExpense($db, $user, $account, '2026-03-10', -5_000);

    $saved = app(SaveReport::class)->save($user, prrCategoryDefinition(ReportViz::Bar->value), 'Spend bars');
    app(TogglePin::class)->toggle($user, $saved->id);

    expect(Livewire::test(PinnedReportsRow::class)->html())
        ->not->toContain('data-testid="pinned-report-legend"');
});
