<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\UnpairsTransferLegs;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

// The survivor of a deleted transfer leg is money that really arrived or
// really left, and only the survivor's own amount says which. Reading it off
// the DELETED leg's type assumes the two legs carry opposite types — and they
// do not: PaypalCsvEventTypeMap types a withdrawal transfer_in on the PayPal
// side while the bank side of the same movement is transfer_in as well, so
// both legs of that pair are transfer_in.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'survivor-direction',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'survivor-asn',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $this->paypal = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'PayPal',
        'slug' => 'survivor-paypal',
        'kind' => AccountKind::Paypal->value,
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => AccountKind::Paypal->value,
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/survivor.csv',
        'sha256' => hash('sha256', 'survivor'),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var UnpairsTransferLegs $unlinker */
    $unlinker = $this->app->make(UnpairsTransferLegs::class);
    $this->unlinker = $unlinker;
});

/**
 * @param  array<string, mixed>  $overrides
 */
function survivorTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::TransferIn->value,
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => 10000,
        'currency' => 'EUR',
        'settled_amount_minor' => 10000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Survivor '.$row,
        'counterparty_normalized' => 'survivor-'.$row,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad((string) $row, 64, 's', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

/**
 * @return array{0: Transaction, 1: Transaction}
 */
function survivorWithdrawalPair(): array
{
    $test = test();

    // A PayPal withdrawal to the bank: money leaves PayPal, arrives at ASN,
    // and the event map types BOTH sides transfer_in.
    $paypalLeg = survivorTx($test->user, $test->paypal, $test->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => -10000,
        'settled_amount_minor' => -10000,
    ]);
    $bankLeg = survivorTx($test->user, $test->bank, $test->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    /** @var PairsTransferLegs $pairer */
    $pairer = app(PairsTransferLegs::class);
    expect($pairer->pairOne($bankLeg, $test->user))->toBe($paypalLeg->id);

    return [$bankLeg, $paypalLeg];
}

it('leaves the arriving survivor as income when both legs were typed transfer_in', function (): void {
    [$bankLeg, $paypalLeg] = survivorWithdrawalPair();

    Transaction::query()->whereKey($paypalLeg->id)->delete();
    expect($bankLeg->refresh()->pair_transaction_id)->toBeNull();

    expect($this->unlinker->unpair($this->user->id, $bankLeg->id, TransactionType::TransferIn))
        ->toBe(TransactionType::Income);
    expect($bankLeg->refresh()->type)->toBe(TransactionType::Income->value);
});

it('leaves the departing survivor as expense when both legs were typed transfer_in', function (): void {
    [$bankLeg, $paypalLeg] = survivorWithdrawalPair();

    Transaction::query()->whereKey($bankLeg->id)->delete();
    expect($paypalLeg->refresh()->pair_transaction_id)->toBeNull();

    expect($this->unlinker->unpair($this->user->id, $paypalLeg->id, TransactionType::TransferIn))
        ->toBe(TransactionType::Expense);
    expect($paypalLeg->refresh()->type)->toBe(TransactionType::Expense->value);
});

it('does not retype a survivor that is no longer a transfer leg', function (): void {
    $expense = survivorTx($this->user, $this->bank, $this->run, [
        'type' => TransactionType::Expense->value,
        'amount_minor' => -4200,
        'settled_amount_minor' => -4200,
    ]);

    expect($this->unlinker->unpair($this->user->id, $expense->id, TransactionType::TransferOut))->toBeNull();
    expect(DB::table('transactions')->where('id', $expense->id)->value('type'))
        ->toBe(TransactionType::Expense->value);
});

it('is a no-op the second time it is asked about the same survivor', function (): void {
    [$bankLeg, $paypalLeg] = survivorWithdrawalPair();

    Transaction::query()->whereKey($paypalLeg->id)->delete();

    expect($this->unlinker->unpair($this->user->id, $bankLeg->id, TransactionType::TransferIn))
        ->toBe(TransactionType::Income);
    expect($this->unlinker->unpair($this->user->id, $bankLeg->id, TransactionType::TransferIn))->toBeNull();
    expect($bankLeg->refresh()->type)->toBe(TransactionType::Income->value);
});
