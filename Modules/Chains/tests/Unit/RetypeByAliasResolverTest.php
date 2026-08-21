<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, bank: Account, paypal: Account, ics: Account, run: ImportRun}
 */
function retypeFixture(string $username): array
{
    static $counter = 0;
    $counter++;
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('ASN-%d', $counter),
        'slug' => sprintf('asn-%d', $counter),
        'kind' => 'bank',
        'iban' => sprintf('NL12ASNB%010d', $counter),
        'default_currency' => 'EUR',
    ]);
    $paypal = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('PayPal-%d', $counter),
        'slug' => sprintf('paypal-%d', $counter),
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $ics = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('ICS-%d', $counter),
        'slug' => sprintf('ics-%d', $counter),
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => sprintf('/tmp/fixture-%d', $counter),
        'sha256' => str_pad((string) $counter, 64, 's', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return compact('user', 'bank', 'paypal', 'ics', 'run');
}

function retypeTx(
    User $user,
    Account $account,
    int $importRunId,
    string $type,
    int $amountMinor,
    ?string $counterpartyIban,
    string $postedAt = '2026-04-30',
): Transaction {
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'fixture-counterparty',
        'counterparty_iban' => $counterpartyIban,
        'counterparty_normalized' => 'FIXTURE',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $importRunId,
        'source_row_index' => $rowIndex,
        'fingerprint' => sprintf('fp-%d', $rowIndex),
        'fingerprint_version' => 1,
    ]);
}

it('retypes a negative expense whose counterparty_iban resolves via the alias bridge to transfer_out', function (): void {
    $f = retypeFixture('alice');
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $f['user']->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $tx = retypeTx($f['user'], $f['bank'], $f['run']->id, 'expense', -1399, 'LU89751000135104200E');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    $touched = $resolver->resolveForUser($f['user']);

    expect($touched)->toBe(1);
    expect($tx->refresh()->type)->toBe('transfer_out');
});

it('retypes a positive income whose counterparty_iban resolves via the alias bridge to transfer_in', function (): void {
    $f = retypeFixture('bob');
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $f['user']->id,
        'real_iban' => 'NL08ABNA0526650664',
        'target_account_kind' => 'ics_card',
    ]);
    $tx = retypeTx($f['user'], $f['bank'], $f['run']->id, 'income', 4567, 'NL08ABNA0526650664');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    $touched = $resolver->resolveForUser($f['user']);

    expect($touched)->toBe(1);
    expect($tx->refresh()->type)->toBe('transfer_in');
});

it('is idempotent — a second pass touches zero rows', function (): void {
    $f = retypeFixture('charlie');
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $f['user']->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    retypeTx($f['user'], $f['bank'], $f['run']->id, 'expense', -1399, 'LU89751000135104200E');
    retypeTx($f['user'], $f['bank'], $f['run']->id, 'expense', -2599, 'LU89751000135104200E', '2026-04-29');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    expect($resolver->resolveForUser($f['user']))->toBe(2);
    expect($resolver->resolveForUser($f['user']))->toBe(0);
});

it('does not touch user B rows when user A is being resolved (per-user scoping)', function (): void {
    $a = retypeFixture('user-a');
    $b = retypeFixture('user-b');
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $a['user']->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $b['user']->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $aTx = retypeTx($a['user'], $a['bank'], $a['run']->id, 'expense', -1399, 'LU89751000135104200E');
    $bTx = retypeTx($b['user'], $b['bank'], $b['run']->id, 'expense', -2599, 'LU89751000135104200E');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    expect($resolver->resolveForUser($a['user']))->toBe(1);

    expect($aTx->refresh()->type)->toBe('transfer_out');
    expect($bTx->refresh()->type)->toBe('expense');
});

it('skips rows whose alias resolves to the row\'s OWN account (degenerate self-transfer guard)', function (): void {
    $f = retypeFixture('diana');
    // Forge a misconfigured alias: the bank-kind alias would resolve
    // a bank-account row back to itself. The resolver must NOT retype.
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $f['user']->id,
        'real_iban' => 'NL99TESTSELF',
        'target_account_kind' => 'bank',
    ]);
    $tx = retypeTx($f['user'], $f['bank'], $f['run']->id, 'expense', -1399, 'NL99TESTSELF');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    expect($resolver->resolveForUser($f['user']))->toBe(0);
    expect($tx->refresh()->type)->toBe('expense');
});

it('skips rows whose counterparty_iban is null or empty', function (): void {
    $f = retypeFixture('elliot');
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $f['user']->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $nullCp = retypeTx($f['user'], $f['bank'], $f['run']->id, 'expense', -1399, null);
    $emptyCp = retypeTx($f['user'], $f['bank'], $f['run']->id, 'expense', -2599, '', '2026-04-29');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    expect($resolver->resolveForUser($f['user']))->toBe(0);
    expect($nullCp->refresh()->type)->toBe('expense');
    expect($emptyCp->refresh()->type)->toBe('expense');
});

it('skips rows whose alias points at a destination kind with no Account yet (the wizard-order race at first preview)', function (): void {
    // The alias still points at `paypal`, so the alias query matches while the
    // EXISTS subquery against `accounts` does not.
    $user = User::query()->create([
        'username' => 'frank-incomplete',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN-frank',
        'slug' => 'asn-frank',
        'kind' => 'bank',
        'iban' => 'NL93ASNB1111111111',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/fixture-frank',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $tx = retypeTx($user, $bank, $run->id, 'expense', -1399, 'LU89751000135104200E');

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    expect($resolver->resolveForUser($user))->toBe(0);
    expect($tx->refresh()->type)->toBe('expense');

    // Now create the paypal account — the next pass picks the row up.
    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal-frank',
        'slug' => 'paypal-frank',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    expect($resolver->resolveForUser($user))->toBe(1);
    expect($tx->refresh()->type)->toBe('transfer_out');
});
