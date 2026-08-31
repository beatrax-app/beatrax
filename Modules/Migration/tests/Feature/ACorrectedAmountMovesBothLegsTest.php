<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;

uses(RefreshDatabase::class);

function acamblUser(): User
{
    return User::query()->create([
        'username' => 'acambl-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function acamblAccount(User $user, string $currency): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'acambl Checking',
        'slug' => 'acambl-checking-'.bin2hex(random_bytes(4)),
        'kind' => 'checking',
        'iban' => 'NL-ACAMBL-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => $currency,
    ]);
}

function acamblSeedTransaction(
    User $user,
    Account $account,
    int $amountMinor,
    string $currency,
    int $settledAmountMinor,
    string $settledCurrency,
    ?string $fxRateUsed,
): int {
    $db = app(DatabaseManager::class);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'ynab4-csv',
        'raw_file_path' => '/tmp/acambl-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'acambl-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    $normalized = 'acambl-const-'.bin2hex(random_bytes(4));

    $canonical = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 09:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: $amountMinor,
        currency: $currency,
        settledAmountMinor: $settledAmountMinor,
        settledCurrency: $settledCurrency,
        counterpartyName: 'Albert Heijn',
        counterpartyIban: 'NL91ABNA0417164300',
        counterpartyNormalized: $normalized,
        normalizationVersion: 3,
        description: 'Weekly groceries',
        categoryId: null,
        sourceFormat: 'ynab4-csv',
        importRunId: $run->id,
        sourceRowIndex: 0,
        sourceRef: 'ACAMBL-'.bin2hex(random_bytes(6)),
    );

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-03-01',
        'booked_at' => '2026-03-01 09:00:00',
        'value_date' => '2026-03-01',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => $settledCurrency,
        'fx_rate_used' => $fxRateUsed,
        'counterparty_name' => 'Albert Heijn',
        'counterparty_iban' => 'NL91ABNA0417164300',
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'description' => 'Weekly groceries',
        'source_format' => 'ynab4-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'source_ref' => $canonical->sourceRef,
        'fingerprint' => app(FingerprintComposer::class)->compose($canonical),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('a corrected single-currency amount moves the settled leg the balance actually sums', function (): void {
    $user = acamblUser();
    $account = acamblAccount($user, 'EUR');
    $transactionId = acamblSeedTransaction($user, $account, -125000, 'EUR', -125000, 'EUR', null);

    $balanceBefore = app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in('EUR');

    expect(app(EntityChangeApplier::class)->applyTransactionAmount($user, $transactionId, -126000))->toBeTrue();

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();

    expect((int) $row->amount_minor)->toBe(-126000);
    expect((int) $row->settled_amount_minor)->toBe(-126000);
    expect((string) $row->settled_currency)->toBe('EUR');
    expect($row->fx_rate_used)->toBeNull();

    // The correction is only real if it reaches the figure every balance,
    // budget, forecast and report sums.
    $balanceAfter = app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in('EUR');
    expect($balanceAfter - $balanceBefore)->toBe(-1000);
})->group('ACorrectedAmountMovesBothLegs');

it('a corrected native amount on a converted row keeps the settled leg and re-derives the rate beside it', function (): void {
    $user = acamblUser();
    $account = acamblAccount($user, 'EUR');
    $transactionId = acamblSeedTransaction($user, $account, -2250, 'USD', -2080, 'EUR', '0.92444444');

    $balanceBefore = app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in('EUR');

    expect(app(EntityChangeApplier::class)->applyTransactionAmount($user, $transactionId, -2000))->toBeTrue();

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();

    // The bank's own conversion is what the euro account moved and no re-import
    // of the dollar figure restates it, so the settled leg stands and the rate
    // is re-derived from the pair it now sits beside: 2080 / 2000 = 1.04.
    expect((int) $row->amount_minor)->toBe(-2000);
    expect((string) $row->currency)->toBe('USD');
    expect((int) $row->settled_amount_minor)->toBe(-2080);
    expect((string) $row->settled_currency)->toBe('EUR');
    expect((float) $row->fx_rate_used)->toBe(1.04);

    $balanceAfter = app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in('EUR');
    expect($balanceAfter - $balanceBefore)->toBe(0);
})->group('ACorrectedAmountMovesBothLegs');

it('a corrected native amount that inverts the sign carries the settled leg with it', function (): void {
    $user = acamblUser();
    $account = acamblAccount($user, 'EUR');
    $transactionId = acamblSeedTransaction($user, $account, -2250, 'USD', -2080, 'EUR', '0.92444444');

    expect(app(EntityChangeApplier::class)->applyTransactionAmount($user, $transactionId, 2250))->toBeTrue();

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();

    // Two legs of one conversion carry one direction, taken from the native leg.
    expect((int) $row->amount_minor)->toBe(2250);
    expect((int) $row->settled_amount_minor)->toBe(2080);
    expect((float) $row->fx_rate_used)->toBeGreaterThan(0.0);
})->group('ACorrectedAmountMovesBothLegs');
