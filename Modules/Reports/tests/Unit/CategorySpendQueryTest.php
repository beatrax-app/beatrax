<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\CategorySpendQuery;

uses(RefreshDatabase::class);

function csqUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'csq-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function csqAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'csq-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00CSQ'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function csqCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function csqImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/csq-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'csq-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function csqTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => csqImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'CSQ Vendor',
        'counterparty_normalized' => 'csq-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'csq-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function csqLeg(DatabaseManager $db, User $user, int $transactionId, int $categoryId, int $settledMinor, array $overrides = []): int
{
    $defaults = [
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transaction_splits')->insertGetId(array_merge($defaults, $overrides));
}

function csqPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );
}

it('sums spend/income/net per category using the canonical type-based definition, excluding transfers', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = csqUser();
    $account = csqAccount($user);
    $groceries = csqCategory('Groceries');

    csqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -12_000, 'category_id' => $groceries->id]);
    csqTransaction($db, $user, $account, ['type' => 'income', 'settled_amount_minor' => 50_000, 'category_id' => $groceries->id]);
    // Internal move between own accounts — must contribute 0.
    csqTransaction($db, $user, $account, ['type' => 'transfer_out', 'settled_amount_minor' => -20_000, 'category_id' => $groceries->id]);

    $query = app(CategorySpendQuery::class);
    $period = csqPeriod();

    $spend = $query->forUserAndPeriod($user, $period, 'spend', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $spend)))->toBe(12_000);

    $income = $query->forUserAndPeriod($user, $period, 'income', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $income)))->toBe(50_000);

    $net = $query->forUserAndPeriod($user, $period, 'net', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $net)))->toBe(38_000);
});

it('attributes split legs to their own categories, excluding the split parent row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = csqUser();
    $account = csqAccount($user);
    $groceries = csqCategory('Groceries');
    $fuel = csqCategory('Fuel');

    $parentId = csqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -10_000]);
    csqLeg($db, $user, $parentId, $groceries->id, -7_000);
    csqLeg($db, $user, $parentId, $fuel->id, -3_000);

    $rows = app(CategorySpendQuery::class)->forUserAndPeriod($user, csqPeriod(), 'spend', 'EUR');
    $byLabel = [];
    foreach ($rows as $row) {
        $byLabel[$row->groupLabel] = $row->amountMinor;
    }

    expect($byLabel['Groceries'] ?? null)->toBe(7_000);
    expect($byLabel['Fuel'] ?? null)->toBe(3_000);
    expect(array_sum($byLabel))->toBe(10_000);
});

it('falls back to the parent category when split legs do not sum to the parent (WR-03 broken split)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = csqUser();
    $account = csqAccount($user);
    $groceries = csqCategory('Groceries');
    $fuel = csqCategory('Fuel');

    $parentId = csqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -10_000, 'category_id' => $groceries->id]);
    // Broken split: only one surviving leg, does not sum to -10_000.
    csqLeg($db, $user, $parentId, $fuel->id, -3_000);

    $rows = app(CategorySpendQuery::class)->forUserAndPeriod($user, csqPeriod(), 'spend', 'EUR');
    $byLabel = [];
    foreach ($rows as $row) {
        $byLabel[$row->groupLabel] = $row->amountMinor;
    }

    expect($byLabel['Groceries'] ?? null)->toBe(10_000);
    expect($byLabel)->not->toHaveKey('Fuel');
});

it('groups uncategorized rows under a null-groupKey Uncategorized bucket', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = csqUser();
    $account = csqAccount($user);

    csqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -5_000, 'category_id' => null]);

    $rows = app(CategorySpendQuery::class)->forUserAndPeriod($user, csqPeriod(), 'spend', 'EUR');

    expect($rows)->toHaveCount(1);
    expect($rows[0]->groupKey)->toBeNull();
    expect($rows[0]->groupLabel)->toBe('Uncategorized');
    expect($rows[0]->amountMinor)->toBe(5_000);
});
