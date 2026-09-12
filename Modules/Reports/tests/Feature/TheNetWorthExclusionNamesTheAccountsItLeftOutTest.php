<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// The banner said "2 accounts not converted" and the reader had no way to tell
// WHICH two: the roll-up is short by their balances and the only route to the
// names was guessing from the account list. The exclusion names them.

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function nwnmReader(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nwnm-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function nwnmAccount(User $user, string $name, string $currency): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'nwnm-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00NWNM'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

function nwnmCredit(User $user, Account $account, int $amountMinor): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwnm-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'nwnm-'.$suffix),
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
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'NWNM Vendor',
        'counterparty_normalized' => 'nwnm-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'nwnm-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nwnmDefinition(): ReportDefinition
{
    return new ReportDefinition(
        metric: 'net_worth',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
    );
}

it('carries the name of every account it left out, not a tally of them', function (): void {
    $user = nwnmReader();
    nwnmCredit($user, nwnmAccount($user, 'Household current', 'EUR'), 100_000);
    // No exchange_rates row exists for JPY or ARS against EUR at any date.
    $tokyo = nwnmAccount($user, 'Tokyo savings', 'JPY');
    $buenos = nwnmAccount($user, 'Buenos Aires cash', 'ARS');
    nwnmCredit($user, $tokyo, 500_000);
    nwnmCredit($user, $buenos, 57_500);

    $result = app(ReportAggregator::class)->run($user, nwnmDefinition());

    expect($result->totalMinor)->toBe(100_000)
        ->and($result->excludedAccounts)->toBe([
            (int) $tokyo->id => 'Tokyo savings',
            (int) $buenos->id => 'Buenos Aires cash',
        ])
        ->and($result->excludedAccountNames())->toBe(['Buenos Aires cash', 'Tokyo savings']);
});

it('names them on the surface, where a count used to stand', function (): void {
    $user = nwnmReader();
    nwnmCredit($user, nwnmAccount($user, 'Household current', 'EUR'), 100_000);
    nwnmCredit($user, nwnmAccount($user, 'Tokyo savings', 'JPY'), 500_000);
    nwnmCredit($user, nwnmAccount($user, 'Buenos Aires cash', 'ARS'), 57_500);
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('metric', 'net_worth')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-04-01')
        ->set('customTo', '2026-04-30')
        ->html();

    expect($html)->toContain(Lang::get('core::money.not_converted', [
        'list' => 'Buenos Aires cash, Tokyo savings',
    ]))
        // The sentence the count used to make, in the shape it used to make it.
        ->and($html)->not->toContain('2 accounts not converted');
});

// One account holding two unconvertible currencies is one account to name, and
// two accounts the reader gave the same name are one name to read.
it('names an account once however many unconvertible currencies it holds', function (): void {
    $user = nwnmReader();
    $one = nwnmAccount($user, 'Travel wallet', 'JPY');
    nwnmCredit($user, $one, 500_000);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('transactions')
        ->where('account_id', $one->id)
        ->update(['currency' => 'ARS', 'settled_currency' => 'ARS']);
    nwnmCredit($user, $one, 300_000);

    $result = app(ReportAggregator::class)->run($user, nwnmDefinition());

    expect($result->excludedAccountNames())->toBe(['Travel wallet']);
});
