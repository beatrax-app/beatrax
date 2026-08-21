<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\AccountSpendQuery;
use Modules\Reports\Internal\Aggregation\SpendQueryFilters;
use Modules\Reports\Internal\Dto\ReportResultRow;

uses(RefreshDatabase::class);

// Driven through AccountSpendQuery, one of its hosts, so every predicate is
// asserted against a real query rather than a mock.

function sfaUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'sfa-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function sfaAccount(User $user, string $name): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'sfa-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00SFA'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sfaTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): void
{
    $suffix = bin2hex(random_bytes(8));
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sfa-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'sfa-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'SFA Vendor',
        'counterparty_normalized' => 'sfa-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'sfa-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $db->connection()->table('transactions')->insert(array_merge($defaults, $overrides));
}

function sfaCategory(string $name): Category
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

function sfaCounterparty(DatabaseManager $db, User $user, string $name): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'sfa-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'display_name' => $name,
        'merchant_name' => strtoupper($name),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function sfaPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );
}

/**
 * @param  list<ReportResultRow>  $rows
 */
function sfaTotalFor(array $rows, int $accountId): int
{
    $total = 0;
    foreach ($rows as $row) {
        if ($row->groupKey === $accountId) {
            $total += $row->amountMinor;
        }
    }

    return $total;
}

it('restricts to the requested account ids', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sfaUser();
    $a = sfaAccount($user, 'A');
    $b = sfaAccount($user, 'B');
    sfaTransaction($db, $user, $a, ['settled_amount_minor' => -5_000]);
    sfaTransaction($db, $user, $b, ['settled_amount_minor' => -3_000]);

    $rows = app(AccountSpendQuery::class)->forUserAndPeriod(
        $user,
        sfaPeriod(),
        'spend',
        'EUR',
        new SpendQueryFilters(accountIds: [$a->id]),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->groupKey)->toBe($a->id)
        ->and($rows[0]->amountMinor)->toBe(5_000);
});

it('restricts to the requested category and counterparty ids', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sfaUser();
    $a = sfaAccount($user, 'A');
    $groceries = sfaCategory('Groceries');
    $utilities = sfaCategory('Utilities');
    $vendorOne = sfaCounterparty($db, $user, 'Vendor One');
    $vendorTwo = sfaCounterparty($db, $user, 'Vendor Two');
    sfaTransaction($db, $user, $a, ['settled_amount_minor' => -5_000, 'category_id' => $groceries->id, 'counterparty_id' => $vendorOne]);
    sfaTransaction($db, $user, $a, ['settled_amount_minor' => -7_000, 'category_id' => $utilities->id, 'counterparty_id' => $vendorTwo]);

    $byCategory = app(AccountSpendQuery::class)->forUserAndPeriod(
        $user,
        sfaPeriod(),
        'spend',
        'EUR',
        new SpendQueryFilters(categoryIds: [$groceries->id]),
    );

    $byCounterparty = app(AccountSpendQuery::class)->forUserAndPeriod(
        $user,
        sfaPeriod(),
        'spend',
        'EUR',
        new SpendQueryFilters(counterpartyIds: [$vendorTwo]),
    );

    expect(sfaTotalFor($byCategory, $a->id))->toBe(5_000)
        ->and(sfaTotalFor($byCounterparty, $a->id))->toBe(7_000);
});

it('restricts to an absolute amount window', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sfaUser();
    $a = sfaAccount($user, 'A');
    sfaTransaction($db, $user, $a, ['settled_amount_minor' => -2_000]);
    sfaTransaction($db, $user, $a, ['settled_amount_minor' => -8_000]);
    sfaTransaction($db, $user, $a, ['settled_amount_minor' => -20_000]);

    $rows = app(AccountSpendQuery::class)->forUserAndPeriod(
        $user,
        sfaPeriod(),
        'spend',
        'EUR',
        new SpendQueryFilters(amountMinMinor: 5_000, amountMaxMinor: 10_000),
    );

    expect(sfaTotalFor($rows, $a->id))->toBe(8_000);
});

it('restricts by amount direction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sfaUser();
    $a = sfaAccount($user, 'A');
    sfaTransaction($db, $user, $a, ['type' => 'expense', 'settled_amount_minor' => -5_000]);
    sfaTransaction($db, $user, $a, ['type' => 'income', 'settled_amount_minor' => 8_000]);

    $inbound = app(AccountSpendQuery::class)->forUserAndPeriod(
        $user,
        sfaPeriod(),
        'net',
        'EUR',
        new SpendQueryFilters(amountDirection: 'in'),
    );

    $outbound = app(AccountSpendQuery::class)->forUserAndPeriod(
        $user,
        sfaPeriod(),
        'net',
        'EUR',
        new SpendQueryFilters(amountDirection: 'out'),
    );

    expect(sfaTotalFor($inbound, $a->id))->toBe(8_000)
        ->and(sfaTotalFor($outbound, $a->id))->toBe(-5_000);
});
