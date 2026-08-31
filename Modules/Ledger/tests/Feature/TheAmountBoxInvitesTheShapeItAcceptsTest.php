<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// The statement box is typed in the account's denomination and the split leg in
// the transaction's own; both spelled their placeholder with two decimals and
// asked the phone for a decimal key, and the split leg wore a pinned euro sign
// over a figure the parser reads at the transaction's currency.

/**
 * @return array{0: User, 1: Account}
 */
function amountShapeAccount(string $currency): array
{
    $user = User::create([
        'username' => 'amount-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $currency,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'Tokyo',
        'slug' => 'amount-shape-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'JP'.bin2hex(random_bytes(6)),
        'default_currency' => $currency,
    ]);

    return [$user, $account];
}

function amountShapeTransaction(User $user, Account $account, string $currency): Transaction
{
    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'amount-shape-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $today = CarbonImmutable::now()->toDateString();

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -1000,
        'currency' => $currency,
        'settled_amount_minor' => -1000,
        'settled_currency' => $currency,
        'counterparty_name' => 'Kiosk',
        'counterparty_normalized' => 'kiosk',
        'normalization_version' => 1,
        'category_id' => $category->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => random_int(1, 999999),
        'fingerprint' => str_pad(bin2hex(random_bytes(8)), 64, '0'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
    ]);
}

it('invites a whole statement figure on a yen account', function (): void {
    [$user, $account] = amountShapeAccount('JPY');

    $html = Livewire::actingAs($user)
        ->test(ReconcilePage::class, ['accountId' => $account->id])
        ->html();

    expect($html)->toContain('placeholder="0"')
        ->and($html)->not->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});

it('still invites two decimals on a euro statement', function (): void {
    [$user, $account] = amountShapeAccount('EUR');

    $html = Livewire::actingAs($user)
        ->test(ReconcilePage::class, ['accountId' => $account->id])
        ->html();

    expect($html)->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="decimal"');
});

it('invites a whole split leg on a yen charge', function (): void {
    [$user, $account] = amountShapeAccount('JPY');
    $tx = amountShapeTransaction($user, $account, 'JPY');

    $html = Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->html();

    expect($html)->toContain('placeholder="0"')
        ->and($html)->not->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});

it('marks a yen split leg with the yen sign', function (): void {
    [$user, $account] = amountShapeAccount('JPY');
    $tx = amountShapeTransaction($user, $account, 'JPY');

    $html = Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->html();

    expect($html)->toContain('<span aria-hidden="true">¥</span>')
        ->and($html)->not->toContain('<span aria-hidden="true">€</span>');
});

it('still marks a euro split leg with the euro sign', function (): void {
    [$user, $account] = amountShapeAccount('EUR');
    $tx = amountShapeTransaction($user, $account, 'EUR');

    $html = Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->html();

    expect($html)->toContain('<span aria-hidden="true">€</span>')
        ->and($html)->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="decimal"');
});
