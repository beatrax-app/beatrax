<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

function rbUser(string $prefix = 'rb'): User
{
    /** @var User */
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function rbAccount(User $user, string $name = 'ASN'): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00RB'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function rbImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rb-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'rb-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function rbTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => rbImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => now()->startOfMonth()->toDateString(),
        'booked_at' => now()->startOfMonth()->toDateTimeString(),
        'value_date' => now()->startOfMonth()->toDateString(),
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RB Vendor',
        'counterparty_normalized' => 'rb-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rb-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('renders live and updates the result when a control changes', function (): void {
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000, 'type' => 'expense']);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => 8_000, 'type' => 'income']);

    Livewire::test(ReportBuilder::class)
        ->assertSee('Reports')
        ->assertSee('50,00') // default metric=spend, category dimension, this_month
        ->set('metric', 'income')
        ->assertSee('80,00');
});

it('shows the friendly empty state when a selection has no results, and the rail stays interactive', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->assertSee('Nothing to show for this selection')
        ->assertSee('Try widening the date range or removing a filter.')
        // The rail is still interactive — a control change is still accepted.
        ->set('metric', 'income')
        ->assertSet('metric', 'income');
});

it('hides the Group-by control when metric is net_worth', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->assertSee('Group by')
        ->set('metric', 'net_worth')
        ->assertDontSee('Group by');
});

it('shows the friendly empty state (not a 500) when Custom range is chosen but dates are not yet filled', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->assertOk()
        ->assertSee('Nothing to show for this selection');
});

it('?report={id} restores a user-owned saved definition into the builder', function (): void {
    $user = rbUser();
    test()->actingAs($user);
    $definition = new ReportDefinition(
        metric: 'income',
        dimension: 'counterparty',
        periodPreset: 'last_3_months',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'bar',
    );
    $saved = app(SaveReport::class)->save($user, $definition, 'My saved report');

    Livewire::test(ReportBuilder::class, ['report' => $saved->id])
        ->assertSet('metric', 'income')
        ->assertSet('dimension', 'counterparty')
        ->assertSet('periodPreset', 'last_3_months')
        ->assertSet('viz', 'bar');
});

it('a foreign report id never loads another user\'s saved report — the builder falls back to its own empty default', function (): void {
    $owner = rbUser('rb-owner');
    $other = rbUser('rb-other');
    test()->actingAs($owner);
    $definition = new ReportDefinition(
        metric: 'income',
        dimension: 'counterparty',
        periodPreset: 'last_3_months',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'bar',
    );
    $saved = app(SaveReport::class)->save($owner, $definition, 'Owner report');

    test()->actingAs($other);

    Livewire::test(ReportBuilder::class, ['report' => $saved->id])
        ->assertSet('metric', 'spend')
        ->assertSet('dimension', 'category')
        ->assertSet('viz', 'table');
});

it('a missing report id falls back to the builder\'s own empty default without erroring', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class, ['report' => 999_999])
        ->assertOk()
        ->assertSet('metric', 'spend');
});

it('reports.index (/reports) renders inside web+auth for an authenticated user', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    test()->get(route('reports.index'))->assertOk()->assertSee('Reports');
});

it('reports.index (/reports) requires authentication', function (): void {
    test()->get(route('reports.index'))->assertRedirect();
});

it('saving a report persists the current builder composition under the given name', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->set('metric', 'income')
        ->call('openSaveForm')
        ->set('saveName', 'My income report')
        ->call('save')
        ->assertSee('Report saved.');

    expect(SavedReport::query()->where('name', 'My income report')->exists())->toBeTrue();
});

it('CR-01: editing a loaded report updates the same row (no duplicate); a fresh save still creates a new one', function (): void {
    $user = rbUser();
    test()->actingAs($user);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
    );
    $saved = app(SaveReport::class)->save($user, $definition, 'Grocery spend');

    expect(SavedReport::query()->count())->toBe(1);

    Livewire::test(ReportBuilder::class, ['report' => $saved->id])
        ->assertSet('loadedReportId', $saved->id)
        ->assertSet('periodPreset', 'this_month')
        ->set('periodPreset', 'last_6_months')
        ->call('openSaveForm')
        ->assertSet('saveName', 'Grocery spend') // Pre-filled from the loaded report.
        ->call('save')
        ->assertSee('Report updated.')
        ->assertSet('loadedReportId', $saved->id);

    expect(SavedReport::query()->count())->toBe(1);

    $saved->refresh();
    expect($saved->name)->toBe('Grocery spend')
        ->and($saved->definition['periodPreset'])->toBe('last_6_months');

    Livewire::test(ReportBuilder::class)
        ->set('metric', 'income')
        ->call('openSaveForm')
        ->assertSet('saveName', '')
        ->set('saveName', 'Income overview')
        ->call('save')
        ->assertSee('Report saved.');

    expect(SavedReport::query()->count())->toBe(2)
        ->and(SavedReport::query()->where('name', 'Income overview')->exists())->toBeTrue();
});

it('renders each of the four visualizations without error, always alongside the same always-on table', function (string $viz, string $expectedVariant): void {
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -2_500]);

    $component = Livewire::test(ReportBuilder::class)->set('viz', $viz)->assertOk();

    // The table renders regardless of the chosen viz.
    $component->assertSeeHtml('<table');

    if ($viz === 'table') {
        $component->assertDontSeeHtml('data-testid="report-chart"');
    } else {
        $component->assertSeeHtml('data-chart-variant="'.$expectedVariant.'"')
            // Every chart carries a per-row drill-down URL to /transactions, never /search.
            ->assertSeeHtml('beatraxDrilldownUrls')
            ->assertDontSeeHtml('/search');
    }
})->with([
    'table' => ['table', 'table'],
    'bar' => ['bar', 'bar'],
    'line' => ['line', 'line'],
    'donut' => ['donut', 'donut'],
]);

it('the donut viz uses a flat series + top-level labels shape, never {name,data}', function (): void {
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);

    // The data-options JSON goes through Blade's default {{ }} escaping, so its
    // quotes arrive as &quot; in the markup.
    $html = Livewire::test(ReportBuilder::class)->set('viz', 'donut')->html();

    expect($html)->toContain('&quot;labels&quot;:')
        ->and($html)->not->toContain('&quot;name&quot;:&quot;Spend&quot;');
});

it('the bar viz uses a {name,data} series shape', function (): void {
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);

    $html = Livewire::test(ReportBuilder::class)->set('viz', 'bar')->html();

    expect($html)->toContain('&quot;name&quot;:&quot;Spend&quot;');
});

it('refreshes the donut by destroy+recreate (never updateOptions, which throws on the axis-less donut)', function (): void {
    // updateOptions() throws inside ApexCharts' convertedCatToNumeric /
    // revertDefaultAxisMinMax axis code on an axis-less donut, so the partial
    // has to destroy() and recreate instead.
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);

    // The x-on:report-updated.window body is emitted as plain HTML text, not
    // entity-escaped like the data-options JSON, so the handler tokens appear
    // verbatim in the rendered markup.
    $html = Livewire::test(ReportBuilder::class)->set('viz', 'donut')->html();

    expect($html)->toContain('chart.destroy()')
        ->and($html)->toContain('new window.ApexCharts')
        ->and($html)->not->toContain('chart.updateOptions');
});

it('keeps the axis-based bar/line viz refreshing via updateOptions, never destroy+recreate', function (string $viz): void {
    // The donut destroy+recreate fix must not leak into the axis-based viz —
    // bar/line keep chart.updateOptions() so their refresh has no flash.
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);

    $html = Livewire::test(ReportBuilder::class)->set('viz', $viz)->html();

    expect($html)->toContain('chart.updateOptions')
        ->and($html)->not->toContain('chart.destroy()');
})->with(['bar', 'line']);

it('mounts every chart through the shared beatraxApplyChartTheme Alpine hook', function (string $viz): void {
    $user = rbUser();
    test()->actingAs($user);
    $db = app(DatabaseManager::class);
    $account = rbAccount($user);
    rbTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);

    Livewire::test(ReportBuilder::class)
        ->set('viz', $viz)
        ->assertSeeHtml('window.beatraxApplyChartTheme');
})->with(['bar', 'line', 'donut']);
