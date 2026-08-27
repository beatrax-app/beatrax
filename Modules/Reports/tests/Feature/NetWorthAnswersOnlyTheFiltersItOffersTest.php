<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// The control rail offered accounts/categories/counterparties/amount for
// net_worth and the query took none of them: filtering to one account returned
// the whole portfolio, with no note that the filter had been dropped. And the
// "N accounts not converted" banner added a per-bucket tally, so one
// unconvertible account over a long range read 4108.

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function nwafUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nwaf-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function nwafAccount(User $user, string $currency): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $currency.' account',
        'slug' => 'nwaf-'.strtolower($currency).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00NWAF'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

function nwafCredit(User $user, Account $account, int $amountMinor, string $postedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwaf-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'nwaf-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $currency = is_string($account->default_currency) ? $account->default_currency : 'EUR';

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'income',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'NWAF Vendor',
        'counterparty_normalized' => 'nwaf-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'nwaf-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<int>  $accounts
 */
function nwafDefinition(array $accounts = [], ?string $amountMin = null): ReportDefinition
{
    return new ReportDefinition(
        metric: 'net_worth',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'line',
        customFrom: '2026-01-01',
        customTo: '2026-06-30',
        accounts: $accounts,
        amountMin: $amountMin,
    );
}

it('restricts the portfolio to the accounts the reader filtered to', function (): void {
    $user = nwafUser();
    $one = nwafAccount($user, 'EUR');
    $two = nwafAccount($user, 'EUR');
    nwafCredit($user, $one, 100_000, '2026-01-05');
    nwafCredit($user, $two, 250_000, '2026-01-05');

    $aggregator = app(ReportAggregator::class);

    expect($aggregator->run($user, nwafDefinition())->totalMinor)->toBe(350_000)
        ->and($aggregator->run($user, nwafDefinition([(int) $one->id]))->totalMinor)->toBe(100_000);
});

it('counts an unconvertible account once for the whole series, not once per bucket', function (): void {
    $user = nwafUser();
    $eur = nwafAccount($user, 'EUR');
    // No exchange_rates row exists for JPY -> EUR at any date.
    $jpy = nwafAccount($user, 'JPY');
    nwafCredit($user, $eur, 100_000, '2026-01-05');
    nwafCredit($user, $jpy, 500_000, '2026-01-05');

    $result = app(ReportAggregator::class)->run($user, nwafDefinition());

    // Six monthly buckets, one account that cannot convert in every one of them.
    expect($result->rows)->toHaveCount(6)
        ->and($result->hasExcludedAccounts)->toBeTrue()
        ->and($result->accountsWithoutRate)->toBe(1);
});

it('does not offer a category, counterparty or amount filter for a balance', function (): void {
    $user = nwafUser();
    nwafAccount($user, 'EUR');
    Category::query()->create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'nwaf-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->assertSee('Amount filter')
        ->assertSee('Category filter')
        ->set('metric', 'net_worth')
        ->assertDontSee('Amount filter')
        ->assertDontSee('Category filter')
        ->assertSee('Account filter');
});

it('says the transaction filters do not apply when a net-worth URL still carries one', function (): void {
    $user = nwafUser();
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->set('metric', 'net_worth')
        ->assertDontSee('Net worth is a balance: only the account filter applies.')
        ->set('filterAmountMin', '10')
        ->assertSee('Net worth is a balance: only the account filter applies.');
});
