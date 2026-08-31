<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\CounterpartySpendQuery;

uses(RefreshDatabase::class);

function cpqUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'cpq-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function cpqAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'cpq-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00CPQ'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function cpqCounterparty(DatabaseManager $db, User $user, string $name): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'cpq-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'display_name' => $name,
        'merchant_name' => strtoupper($name),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cpqImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cpq-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'cpq-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cpqTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => cpqImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'CPQ Vendor',
        'counterparty_normalized' => 'cpq-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'cpq-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function cpqPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );
}

it('groups spend/income/net by counterparty using the canonical type-based definition, excluding transfers', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cpqUser();
    $account = cpqAccount($user);
    $vendorOne = cpqCounterparty($db, $user, 'Vendor One');
    $vendorTwo = cpqCounterparty($db, $user, 'Vendor Two');

    cpqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -12_000, 'counterparty_id' => $vendorOne]);
    cpqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -3_000, 'counterparty_id' => $vendorTwo]);
    cpqTransaction($db, $user, $account, ['type' => 'income', 'settled_amount_minor' => 50_000, 'counterparty_id' => $vendorOne]);
    // Internal move between own accounts — must contribute 0.
    cpqTransaction($db, $user, $account, ['type' => 'transfer_out', 'settled_amount_minor' => -20_000, 'counterparty_id' => $vendorOne]);

    $query = app(CounterpartySpendQuery::class);
    $period = cpqPeriod();

    $spend = $query->forUserAndPeriod($user, $period, 'spend', 'EUR');
    $byLabel = [];
    foreach ($spend as $row) {
        $byLabel[$row->groupLabel] = $row->amountMinor;
    }
    expect($byLabel['Vendor One'])->toBe(12_000);
    expect($byLabel['Vendor Two'])->toBe(3_000);
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $spend)))->toBe(15_000);

    $income = $query->forUserAndPeriod($user, $period, 'income', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $income)))->toBe(50_000);

    $net = $query->forUserAndPeriod($user, $period, 'net', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $net)))->toBe(35_000);
});

it('groups rows with no counterparty under a null-groupKey bucket the reader\'s own language names', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cpqUser();
    $account = cpqAccount($user);

    cpqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -5_000, 'counterparty_id' => null]);

    $rows = app(CounterpartySpendQuery::class)->forUserAndPeriod($user, cpqPeriod(), 'spend', 'EUR');

    expect($rows)->toHaveCount(1);
    expect($rows[0]->groupKey)->toBeNull();
    expect($rows[0]->groupLabel)->toBe(Lang::get('reports::builder.no_counterparty'));
    expect($rows[0]->amountMinor)->toBe(5_000);
});
