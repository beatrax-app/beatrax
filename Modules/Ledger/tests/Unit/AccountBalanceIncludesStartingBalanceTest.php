<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

uses(RefreshDatabase::class);

function sbbUser(string $suffix): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'sbb-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sbbAccount(User $user, string $suffix, array $overrides = []): Account
{
    /** @var Account */
    return Account::query()->create(array_merge([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'sbb-'.$suffix,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57SBB'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => Currency::Eur->value,
    ], $overrides));
}

// Raw insert, not Eloquent: the immutable_date cast writes posted_at with a
// midnight time component, and a date-only column holding "2026-05-10 00:00:00"
// compares differently from the bare "2026-05-10" every import path stores.
function sbbTransaction(User $user, Account $account, int $runId, string $postedAt, int $amountMinor, ClearedStatus $status = ClearedStatus::Cleared): void
{
    static $sbbRow = 0;
    $sbbRow++;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 09:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'SBB Merchant '.$sbbRow,
        'counterparty_normalized' => 'sbb merchant '.$sbbRow,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => $sbbRow,
        'fingerprint' => hash('sha256', 'sbb-'.$sbbRow.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'status' => $status->value,
        'created_at' => $postedAt.' 09:00:00',
        'updated_at' => $postedAt.' 09:00:00',
    ]);
}

it('adds a dateless baseline — the demo-seeder shape — to every row it holds', function (): void {
    $user = sbbUser('dateless');
    $account = sbbAccount($user, 'dateless', ['starting_balance_minor' => 285_000]);
    $run = $this->makeImportRun($user);

    sbbTransaction($user, $account, $run->id, '2020-01-01', -1_000);
    sbbTransaction($user, $account, $run->id, '2026-05-20', -2_000);

    expect(app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in(Currency::Eur->value))->toBe(282_000);
});

it('does not count a row posted before a dated baseline twice', function (): void {
    $user = sbbUser('bounded');
    $account = sbbAccount($user, 'bounded', [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-05-10',
    ]);
    $run = $this->makeImportRun($user);

    sbbTransaction($user, $account, $run->id, '2026-05-05', -5_000);
    sbbTransaction($user, $account, $run->id, '2026-05-20', -2_000);

    expect(app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in(Currency::Eur->value))->toBe(98_000);
});

it('counts a row posted exactly on the baseline date, which is the position before that day', function (): void {
    $user = sbbUser('boundary');
    $account = sbbAccount($user, 'boundary', [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-05-10',
    ]);
    $run = $this->makeImportRun($user);

    sbbTransaction($user, $account, $run->id, '2026-05-10', -1_000);

    expect(app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in(Currency::Eur->value))->toBe(99_000);
});

it('leaves an account carrying no baseline at the bare transaction sum', function (): void {
    $user = sbbUser('none');
    $account = sbbAccount($user, 'none');
    $run = $this->makeImportRun($user);

    sbbTransaction($user, $account, $run->id, '2026-05-12', -1_500);

    expect(app(AccountBalanceQuery::class)->currentBalance($account->id, $user)->in(Currency::Eur->value))->toBe(-1_500);
});

it('adds the baseline to the cleared balance while still excluding uncleared rows', function (): void {
    $user = sbbUser('cleared');
    $account = sbbAccount($user, 'cleared', ['starting_balance_minor' => 50_000]);
    $run = $this->makeImportRun($user);

    sbbTransaction($user, $account, $run->id, '2026-05-12', -1_000);
    sbbTransaction($user, $account, $run->id, '2026-05-13', -2_000, ClearedStatus::Reconciled);
    sbbTransaction($user, $account, $run->id, '2026-05-14', -9_000, ClearedStatus::Uncleared);

    expect(app(AccountBalanceQuery::class)->clearedBalance($account->id, $user)->in(Currency::Eur->value))->toBe(47_000);
});

it('adds the baseline to the as-of balance the reconcile screen compares against a statement', function (): void {
    $user = sbbUser('asof');
    $account = sbbAccount($user, 'asof', [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-05-10',
    ]);
    $run = $this->makeImportRun($user);

    sbbTransaction($user, $account, $run->id, '2026-05-01', -4_000);
    sbbTransaction($user, $account, $run->id, '2026-05-10', -1_000);
    sbbTransaction($user, $account, $run->id, '2026-05-31', -3_000);

    $asOf = CarbonImmutable::parse('2026-05-15');

    expect(app(AccountBalanceQuery::class)->clearedBalanceAsOf($account->id, $user, $asOf)->in(Currency::Eur->value))->toBe(99_000);
});

it('gives a foreign user none of the account owner\'s baseline', function (): void {
    $owner = sbbUser('owner');
    $intruder = sbbUser('intruder');
    $account = sbbAccount($owner, 'owner', ['starting_balance_minor' => 285_000]);
    $run = $this->makeImportRun($owner);

    sbbTransaction($owner, $account, $run->id, '2026-05-12', -1_000);

    expect(app(AccountBalanceQuery::class)->currentBalance($account->id, $intruder)->in(Currency::Eur->value))->toBe(0);
});
