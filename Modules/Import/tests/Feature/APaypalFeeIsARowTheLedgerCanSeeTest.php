<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

// paypal-fee-wallet.csv sums Bruto to 15,00, Kosten to -3,94 and Netto to
// 11,06. PayPal's Saldo column ends on 11,06, so Netto is what the wallet
// moved by and Bruto is what the ledger claimed before the fee had a row.
const PAYPAL_FEE_IMPORT = 'Modules/Ingestion/tests/fixtures/paypal/paypal-fee-wallet.csv';

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    /** @var array{user: User, paypalAccount: Account} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->paypalAccount = $seed['paypalAccount'];
    $this->importer = $this->app->make(RunsImports::class);
});

function importThePaypalFeeWallet(User $user): int
{
    /** @var RunsImports $importer */
    $importer = test()->importer;

    return $importer->runAndConfirm(
        base_path(PAYPAL_FEE_IMPORT),
        'paypal-csv',
        $user,
        'paypal-fee-wallet.csv',
    )->inserted;
}

it('lands the wallet on the balance PayPal says it landed on', function (): void {
    expect(importThePaypalFeeWallet($this->user))->toBe(11);

    $balance = $this->app->make(AccountBalanceQuery::class)
        ->currentBalance($this->paypalAccount->id, $this->user);

    // 1500 is what the account read while every row booked Bruto, and the 394
    // between the two figures is the fee column nothing consumed.
    expect($balance->in(Currency::Eur->value))->toBe(1106);
});

it('gives every fee PayPal charged a row of its own', function (): void {
    importThePaypalFeeWallet($this->user);

    $fees = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('type', TransactionType::Fee->value)
        ->orderBy('source_row_index')
        ->get();

    // Five, not six: the one payment whose Kosten cell reads 0,00 gets no row,
    // because a movement of nothing is not a movement.
    expect($fees)->toHaveCount(5);
    expect($fees->pluck('amount_minor')->all())->toBe([-349, -50, 125, -60, -60]);
    expect((int) $fees->sum('amount_minor'))->toBe(-394);
    expect($fees->pluck('payment_type')->unique()->all())->toBe([PaymentType::Fee]);
});

it('names the payment each fee was charged on', function (): void {
    importThePaypalFeeWallet($this->user);

    $refs = Transaction::query()
        ->where('user_id', $this->user->id)
        ->orderBy('source_row_index')
        ->get()
        ->groupBy('source_ref');

    $charged = $refs->get('O-00000000000000101');

    expect($charged)->toHaveCount(2);
    expect($charged?->pluck('type')->all())
        ->toBe([TransactionType::TransferIn->value, TransactionType::Fee->value]);

    // The payment with no fee keeps the one row it always had, so a shared
    // reference never implies a pair that is not there.
    expect($refs->get('O-00000000000000103'))->toHaveCount(1);
});

// The fee rows differ from their payments in amount alone, and two of them
// differ from each other in nothing the dedup tuple reads but the occurrence
// ordinal. Both are counted over the file rather than the ledger, so the
// second import recognises all eleven rows instead of writing five fees again.
it('writes no second copy of a fee when the same export is imported again', function (): void {
    expect(importThePaypalFeeWallet($this->user))->toBe(11);

    /** @var RunsImports $importer */
    $importer = $this->importer;
    $second = $importer->runAndConfirm(
        base_path(PAYPAL_FEE_IMPORT),
        'paypal-csv',
        $this->user,
        'paypal-fee-wallet.csv',
    );

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(11);

    $rows = Transaction::query()->where('user_id', $this->user->id);

    expect($rows->clone()->count())->toBe(11);
    expect($rows->clone()->where('type', TransactionType::Fee->value)->count())->toBe(5);
    expect((int) $rows->clone()->sum('settled_amount_minor'))->toBe(1106);
});
