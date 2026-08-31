<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// A report row is the figure AFTER every filter the reader set. The list the row
// opens has to be narrowed the same way, or the two disagree about money.

function dfkUser(string $baseCurrency = 'EUR'): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'dfk-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => $baseCurrency,
    ]);
}

function dfkAccount(User $user, string $slug, string $currency = 'EUR'): Account
{
    /** @var Account */
    return Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => $slug],
        [
            'name' => 'dfk '.$slug,
            'kind' => 'bank',
            'iban' => 'NL00DFK'.str_pad((string) crc32($slug.$user->id), 11, '0', STR_PAD_LEFT),
            'default_currency' => $currency,
        ],
    );
}

function dfkCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => 'dfk-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function dfkExpense(User $user, Account $account, ?int $categoryId, int $amountMinor, string $postedAt, string $vendor, string $currency = 'EUR'): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dfk-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dfk-'.$suffix),
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
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'category_id' => $categoryId,
        'counterparty_name' => $vendor,
        'counterparty_normalized' => strtolower($vendor),
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'dfk-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $params
 */
function dfkListSum(array $params): int
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

/**
 * @param  array<string, mixed>  $set
 * @return array{amountMinor: int, params: array<string, mixed>}
 */
function dfkRowAndParams(array $set, string $label): array
{
    $page = Livewire::test(ReportBuilder::class)
        ->set('metric', 'spend')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-01-01')
        ->set('customTo', '2026-01-31');

    foreach ($set as $property => $value) {
        $page = $page->set($property, $value);
    }

    /** @var object $result */
    $result = $page->viewData('result');
    /** @var list<string> $urls */
    $urls = $page->viewData('drilldownUrls');

    foreach ($result->rows as $index => $row) {
        if ($row->groupLabel !== $label) {
            continue;
        }

        $query = parse_url($urls[$index] ?? '', PHP_URL_QUERY);
        $params = [];
        parse_str(is_string($query) ? $query : '', $params);

        return ['amountMinor' => $row->amountMinor, 'params' => $params];
    }

    return ['amountMinor' => 0, 'params' => []];
}

it('narrows a category drilldown by the account filter the figure was narrowed by', function (): void {
    $user = dfkUser();
    $this->actingAs($user);

    $office = dfkCategory('Office');
    $current = dfkAccount($user, 'dfk-current');
    $savings = dfkAccount($user, 'dfk-savings');

    dfkExpense($user, $current, $office->id, -10_000, '2026-01-05', 'DFK Stationers');
    dfkExpense($user, $savings, $office->id, -4_000, '2026-01-06', 'DFK Stationers');

    $row = dfkRowAndParams(['dimension' => 'category', 'filterAccounts' => [$current->id]], 'Office');

    expect($row['amountMinor'])->toBe(10_000)
        ->and(dfkListSum($row['params']) * -1)->toBe($row['amountMinor']);
});

it('narrows a counterparty drilldown by the category filter the figure was narrowed by', function (): void {
    $user = dfkUser();
    $this->actingAs($user);

    $office = dfkCategory('Office');
    $travel = dfkCategory('Travel');
    $current = dfkAccount($user, 'dfk-current');

    dfkExpense($user, $current, $office->id, -10_000, '2026-01-05', 'DFK Stationers');
    dfkExpense($user, $current, $travel->id, -4_000, '2026-01-06', 'DFK Stationers');

    $row = dfkRowAndParams(['dimension' => 'counterparty', 'filterCategories' => [$office->id]], Lang::get('reports::builder.no_counterparty'));

    expect($row['amountMinor'])->toBe(10_000)
        ->and(dfkListSum($row['params']) * -1)->toBe($row['amountMinor']);
});

it('narrows a time-bucket drilldown by the counterparty filter the figure was narrowed by', function (): void {
    $user = dfkUser();
    $this->actingAs($user);

    $current = dfkAccount($user, 'dfk-current');
    $office = dfkCategory('Office');

    $kept = dfkExpense($user, $current, $office->id, -10_000, '2026-01-05', 'DFK Stationers');
    dfkExpense($user, $current, $office->id, -4_000, '2026-01-06', 'DFK Kiosk');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $counterpartyId = (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'slug' => 'dfk-stationers',
        'type' => 'merchant',
        'display_name' => 'DFK Stationers',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $db->connection()->table('transactions')->where('id', $kept)->update(['counterparty_id' => $counterpartyId]);

    $row = dfkRowAndParams(
        ['dimension' => 'time_bucket', 'filterCounterparties' => [$counterpartyId]],
        'Jan 2026',
    );

    expect($row['amountMinor'])->toBe(10_000)
        ->and(dfkListSum($row['params']) * -1)->toBe($row['amountMinor']);
});

// The bound is one amount of money, and both surfaces have to read it at the
// same scale. The report reads it at the reader's currency; the list it opens
// read it at a hard two decimals, so "at least 20" meant ¥20 on the figure and
// ¥2 000 on the rows behind it.
it('reads the amount bound at the same scale on both sides of the drilldown', function (): void {
    $user = dfkUser('JPY');
    $this->actingAs($user);

    $office = dfkCategory('Office');
    $account = dfkAccount($user, 'dfk-yen', 'JPY');

    dfkExpense($user, $account, $office->id, -500, '2026-01-05', 'DFK Kiosk', 'JPY');
    dfkExpense($user, $account, $office->id, -3_000, '2026-01-06', 'DFK Stationers', 'JPY');

    $row = dfkRowAndParams(['dimension' => 'category', 'filterAmountMin' => '20'], 'Office');

    expect($row['amountMinor'])->toBe(3_500)
        ->and($row['params']['amount_min'] ?? null)->toBe('20')
        ->and(dfkListSum($row['params']) * -1)->toBe($row['amountMinor']);
});
