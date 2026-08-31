<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// A category id this device cannot resolve fell back to the "Uncategorized"
// LABEL while keeping its own group key, so the table, the pinned donut legend
// and the dashboard movers each showed two rows with the same name and no way
// to tell which was which.

function trcUser(string $suffix = ''): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'trc'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function trcSpend(User $user, ?int $categoryId, int $minor, string $postedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'trc-'.$user->id],
        ['name' => 'trc account', 'kind' => 'bank', 'iban' => 'NL00TRC'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/trc-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'trc-'.$suffix),
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
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'category_id' => $categoryId,
        'counterparty_name' => 'TRC Vendor',
        'counterparty_normalized' => 'trc-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'trc-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function trcForeignCategory(): int
{
    /** @var Category $category */
    $category = Category::query()->create([
        'user_id' => trcUser('-neighbour')->id,
        'name' => 'Their Groceries',
        'slug' => 'trc-foreign-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    return $category->id;
}

it('tells a category it cannot see apart from having no category at all', function (): void {
    $user = trcUser();
    trcSpend($user, null, -8_500, '2026-05-04');
    trcSpend($user, trcForeignCategory(), -10_000, '2026-05-06');

    $result = app(ReportAggregator::class)->run($user, new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-05-01',
        customTo: '2026-05-31',
    ));

    $labels = array_map(static fn ($row): string => $row->groupLabel, $result->rows);
    sort($labels);

    expect($labels)->toBe([
        Lang::get('ledger::common.unavailable_category'),
        Lang::get('reports::builder.uncategorized'),
    ])->and(array_unique($labels))->toHaveCount(2);
});

it('tells them apart on the dashboard movers too', function (): void {
    $user = trcUser();
    $today = now()->startOfMonth()->addDays(3)->toDateString();
    trcSpend($user, null, -8_500, $today);
    trcSpend($user, trcForeignCategory(), -10_000, $today);
    test()->actingAs($user);

    $trend = app(CategorySpendTrendQuery::class)->forUser($user);
    $names = array_map(static fn ($mover): string => $mover->name, $trend->movers);

    expect($names)->toContain(Lang::get('ledger::common.uncategorized'))
        ->and($names)->toContain(Lang::get('ledger::common.unavailable_category'))
        ->and(array_unique($names))->toHaveCount(count($names));
});

it('leaves a translated noun in the headline the case its language wrote it in', function (): void {
    $user = trcUser();
    $user->forceFill(['locale' => 'de'])->save();
    trcSpend($user, null, -8_500, '2026-05-04');
    test()->actingAs($user);
    app()->setLocale('de');

    $html = Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-05-01')
        ->set('customTo', '2026-05-31')
        ->html();

    // strtolower() turned the German noun into "ausgaben" in the DOM and in
    // everything that reads it aloud; CSS only made it invisible on screen.
    expect($html)->toContain('Gesamt Ausgaben')
        ->and($html)->not->toContain('Gesamt ausgaben');
});
