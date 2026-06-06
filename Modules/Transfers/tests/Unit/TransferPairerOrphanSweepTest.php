<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

uses(RefreshDatabase::class);

/*
 * Coverage for {@see PairsTransferLegs::pairOrphansForUser} — the
 * bulk-sweep entry point added so the chain-resolution job can close
 * pairs on rows that the per-row TransactionImported listener path
 * missed (the wizard-order race retyped them AFTER their import).
 *
 *   1. Pairs two unpaired-but-already-typed transfer legs that match
 *      via the alias bridge.
 *   2. Pairs two legs that match via literal own-IBAN equality (no
 *      alias bridge needed).
 *   3. Leaves already-paired rows alone (idempotency).
 *   4. Returns the number of NEW pairs written; re-running returns 0.
 *   5. Per-user scoped.
 */

/**
 * Build a user + bank/paypal account + the LU alias + one import run.
 *
 * @return array{user: User, bank: Account, paypal: Account, run: ImportRun}
 */
function orphanFixture(string $username): array
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
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => sprintf('/tmp/orphan-%d', $counter),
        'sha256' => str_pad((string) $counter, 64, 'o', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return compact('user', 'bank', 'paypal', 'run');
}

function orphanTx(
    User $user,
    Account $account,
    int $importRunId,
    string $type,
    int $amountMinor,
    ?string $counterpartyIban,
    string $bookedAt = '2026-04-30 12:00:00',
): Transaction {
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'fixture',
        'counterparty_iban' => $counterpartyIban,
        'counterparty_normalized' => 'FIXTURE',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $importRunId,
        'source_row_index' => $rowIndex,
        'fingerprint' => sprintf('orph-fp-%d', $rowIndex),
        'fingerprint_version' => 1,
    ]);
}

it('pairs two unpaired transfer legs that match via the alias bridge', function (): void {
    $f = orphanFixture('alpha');
    $asn = orphanTx($f['user'], $f['bank'], $f['run']->id, 'transfer_out', -1399, 'LU89751000135104200E');
    $paypal = orphanTx($f['user'], $f['paypal'], $f['run']->id, 'transfer_in', 1399, null, '2026-04-30 00:00:00');

    /** @var PairsTransferLegs $pairer */
    $pairer = $this->app->make(PairsTransferLegs::class);
    $paired = $pairer->pairOrphansForUser($f['user']);

    expect($paired)->toBe(1);
    expect($asn->refresh()->pair_transaction_id)->toBe($paypal->id);
    expect($paypal->refresh()->pair_transaction_id)->toBe($asn->id);
});

it('pairs two legs that match via literal own-IBAN equality (no alias bridge needed)', function (): void {
    $f = orphanFixture('beta');
    // ASN row points DIRECTLY at the PayPal account's own iban literal.
    $asn = orphanTx($f['user'], $f['bank'], $f['run']->id, 'transfer_out', -2599, 'PAYPAL');
    $paypal = orphanTx($f['user'], $f['paypal'], $f['run']->id, 'transfer_in', 2599, null);

    /** @var PairsTransferLegs $pairer */
    $pairer = $this->app->make(PairsTransferLegs::class);
    $paired = $pairer->pairOrphansForUser($f['user']);

    expect($paired)->toBe(1);
    expect($asn->refresh()->pair_transaction_id)->toBe($paypal->id);
});

it('leaves already-paired rows alone (idempotency on the second pass)', function (): void {
    $f = orphanFixture('gamma');
    $asn = orphanTx($f['user'], $f['bank'], $f['run']->id, 'transfer_out', -1399, 'LU89751000135104200E');
    $paypal = orphanTx($f['user'], $f['paypal'], $f['run']->id, 'transfer_in', 1399, null);

    /** @var PairsTransferLegs $pairer */
    $pairer = $this->app->make(PairsTransferLegs::class);
    expect($pairer->pairOrphansForUser($f['user']))->toBe(1);
    expect($pairer->pairOrphansForUser($f['user']))->toBe(0);
    expect($asn->refresh()->pair_transaction_id)->toBe($paypal->id);
});

it('is per-user scoped — never pairs across users', function (): void {
    $a = orphanFixture('hank');
    $b = orphanFixture('iris');
    // Both users have an identical-amount unpaired ASN/PayPal pair.
    // The sweep for A must NOT pair A's ASN row with B's PayPal row.
    orphanTx($a['user'], $a['bank'], $a['run']->id, 'transfer_out', -1399, 'LU89751000135104200E');
    orphanTx($b['user'], $b['paypal'], $b['run']->id, 'transfer_in', 1399, null);

    /** @var PairsTransferLegs $pairer */
    $pairer = $this->app->make(PairsTransferLegs::class);
    expect($pairer->pairOrphansForUser($a['user']))->toBe(0);
    expect($pairer->pairOrphansForUser($b['user']))->toBe(0);
});
