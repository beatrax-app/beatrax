<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// A refund is positive and `spend` counts it, so the direction the builder used
// to add dropped from the list the very rows the figure had subtracted: a 100
// charger and a 30 refund read as 70 on the report and 100 in the list, with no
// way for the reader to tell which number was wrong.

function ddlUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'ddl-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function ddlMovement(User $user, TransactionType $type, int $amountMinor, string $postedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'ddl-'.$user->id],
        ['name' => 'ddl account', 'kind' => 'bank', 'iban' => 'NL00DDL'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ddl-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ddl-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => $type->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'DDL Electronics',
        'counterparty_normalized' => 'ddl-electronics',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'ddl-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array{total: int, params: array<string, mixed>}
 */
function ddlReportRow(string $metric): array
{
    $page = Livewire::test(ReportBuilder::class)
        ->set('metric', $metric)
        ->set('dimension', 'counterparty')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-01-01')
        ->set('customTo', '2026-01-31');

    $result = $page->viewData('result');
    $urls = $page->viewData('drilldownUrls');

    $query = parse_url(is_array($urls) ? (string) ($urls[0] ?? '') : '', PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);

    return ['total' => $result->rows[0]->amountMinor, 'params' => $params];
}

/**
 * @param  array<string, mixed>  $params
 */
function ddlListSum(array $params): int
{
    $rows = Livewire::withQueryParams($params)
        ->test(TransactionsList::class)
        ->get('accumulatedRows');

    $sum = 0;
    foreach (is_array($rows) ? $rows : [] as $row) {
        $sum += (int) $row['amountMinor'];
    }

    return $sum;
}

beforeEach(function (): void {
    $this->user = ddlUser();
    $this->actingAs($this->user);

    ddlMovement($this->user, TransactionType::Expense, -10_000, '2026-01-05');
    ddlMovement($this->user, TransactionType::Refund, 3_000, '2026-01-09');
    ddlMovement($this->user, TransactionType::Income, 250_000, '2026-01-10');
    ddlMovement($this->user, TransactionType::Fee, -500, '2026-01-11');
    ddlMovement($this->user, TransactionType::TransferOut, -2_000, '2026-01-12');
});

it('opens a list that adds up to the figure the row showed', function (string $metric, int $sign): void {
    $row = ddlReportRow($metric);

    expect(ddlListSum($row['params']) * $sign)->toBe($row['total']);
})->with([
    // `spend` is summed as SUM(-amount), so the list's own signed sum is its
    // negation; `income` and `net` are summed as they are stored.
    'spend' => ['spend', -1],
    'income' => ['income', 1],
    'net' => ['net', 1],
]);

it('leaves a refund in the list a spend figure already subtracted', function (): void {
    $row = ddlReportRow('spend');

    expect($row['total'])->toBe(7_000)
        ->and($row['params'])->not->toHaveKey('amount_dir')
        ->and($row['params']['type'] ?? null)->toBe(['expense', 'refund']);
});

it('keeps a direction the reader chose', function (): void {
    $page = Livewire::test(ReportBuilder::class)
        ->set('metric', 'net')
        ->set('dimension', 'counterparty')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-01-01')
        ->set('customTo', '2026-01-31')
        ->set('filterAmountDir', 'out');

    $urls = $page->viewData('drilldownUrls');
    $query = parse_url(is_array($urls) ? (string) ($urls[0] ?? '') : '', PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);

    expect($params['amount_dir'] ?? null)->toBe('out');
});

// The cases above group by counterparty, write no splits, and give every row a
// counterparty, so no null bucket exists. Category has both: a null group key
// emitted no filter at all, and `category[]=N` tested the parent column, hiding
// every split parent whose LEG the figure had counted.

function ddlCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => 'ddl-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function ddlCategorisedMovement(User $user, ?int $categoryId, int $amountMinor, string $postedAt, string $vendor): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'ddl-'.$user->id],
        ['name' => 'ddl account', 'kind' => 'bank', 'iban' => 'NL00DDL'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ddl-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ddl-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => TransactionType::Expense->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'category_id' => $categoryId,
        'counterparty_name' => $vendor,
        'counterparty_normalized' => strtolower($vendor),
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'ddl-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ddlSplit(User $user, int $transactionId, int $categoryId, int $minor): void
{
    app(DatabaseManager::class)->connection()->table('transaction_splits')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array{rows: list<object>, urls: list<string>}
 */
function ddlCategoryReport(): array
{
    $page = Livewire::test(ReportBuilder::class)
        ->set('metric', 'spend')
        ->set('dimension', 'category')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-01-01')
        ->set('customTo', '2026-01-31');

    /** @var object $result */
    $result = $page->viewData('result');
    /** @var list<string> $urls */
    $urls = $page->viewData('drilldownUrls');

    return ['rows' => $result->rows, 'urls' => $urls];
}

/**
 * @param  array{rows: list<object>, urls: list<string>}  $report
 * @return array<string, mixed>
 */
function ddlParamsFor(array $report, string $label): array
{
    foreach ($report['rows'] as $index => $row) {
        if ($row->groupLabel !== $label) {
            continue;
        }

        $query = parse_url($report['urls'][$index] ?? '', PHP_URL_QUERY);
        $params = [];
        parse_str(is_string($query) ? $query : '', $params);

        return $params;
    }

    return [];
}

it('opens the uncategorized bucket on a list of exactly what it counted', function (): void {
    $user = ddlUser();
    $this->actingAs($user);

    $office = ddlCategory('Office');
    ddlCategorisedMovement($user, null, -8_500, '2026-01-05', 'DDL Kiosk');
    ddlCategorisedMovement($user, $office->id, -10_000, '2026-01-06', 'DDL Stationers');

    $report = ddlCategoryReport();
    $params = ddlParamsFor($report, 'Uncategorized');

    // The whole period used to come back: no dimension filter was emitted at
    // all, so a row reading 85.00 opened every transaction in the window.
    expect($params)->not->toBe([])
        ->and(ddlListSum($params))->toBe(-8_500);
});

it('shows the split parent whose leg a category row counted', function (): void {
    $user = ddlUser();
    $this->actingAs($user);

    $care = ddlCategory('Personal care');
    $office = ddlCategory('Office');

    // Fully attributed to one category: parent and legs are the same money, so
    // the list can add up to the row exactly.
    $whole = ddlCategorisedMovement($user, null, -10_000, '2026-01-07', 'DDL Pharmacy');
    ddlSplit($user, $whole, $care->id, -6_000);
    ddlSplit($user, $whole, $care->id, -4_000);

    // Attributed across two: the list can only show the parent, but showing
    // nothing at all is what left the row with no transactions behind it.
    $shared = ddlCategorisedMovement($user, null, -2_105, '2026-01-08', 'DDL Drugstore');
    ddlSplit($user, $shared, $care->id, -1_000);
    ddlSplit($user, $shared, $office->id, -1_105);

    $report = ddlCategoryReport();
    $params = ddlParamsFor($report, 'Personal care');

    $rows = Livewire::withQueryParams($params)
        ->test(TransactionsList::class)
        ->get('accumulatedRows');

    $ids = array_map(static fn (array $row): int => (int) $row['id'], is_array($rows) ? $rows : []);
    sort($ids);
    $expected = [$whole, $shared];
    sort($expected);

    expect($ids)->toBe($expected);
});

it('adds up to the row when every leg of the split is in the row category', function (): void {
    $user = ddlUser();
    $this->actingAs($user);

    $care = ddlCategory('Personal care');

    $whole = ddlCategorisedMovement($user, null, -10_000, '2026-01-07', 'DDL Pharmacy');
    ddlSplit($user, $whole, $care->id, -6_000);
    ddlSplit($user, $whole, $care->id, -4_000);
    ddlCategorisedMovement($user, $care->id, -2_105, '2026-01-08', 'DDL Drugstore');

    $report = ddlCategoryReport();
    $params = ddlParamsFor($report, 'Personal care');

    $row = null;
    foreach ($report['rows'] as $candidate) {
        if ($candidate->groupLabel === 'Personal care') {
            $row = $candidate;
        }
    }

    expect($row?->amountMinor)->toBe(12_105)
        ->and(ddlListSum($params) * -1)->toBe($row?->amountMinor);
});
