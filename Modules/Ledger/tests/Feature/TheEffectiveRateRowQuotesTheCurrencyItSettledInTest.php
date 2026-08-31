<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\Rate;

uses(RefreshDatabase::class);

function crossCurrencyRow(int $nativeMinor, string $nativeCurrency, int $settledMinor, string $settledCurrency): array
{
    $user = User::create([
        'username' => 'fx-row-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'Card',
        'slug' => 'card-'.bin2hex(random_bytes(4)),
        'kind' => 'ics_card',
        'iban' => 'ICS'.bin2hex(random_bytes(6)),
        'default_currency' => $settledCurrency,
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'ics_pdf',
        'raw_file_path' => '/tmp/x.pdf',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $today = CarbonImmutable::now()->toDateString();
    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => $nativeMinor,
        'currency' => $nativeCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $settledCurrency,
        'fx_rate_used' => (string) Rate::between(
            Money::ofMinor($settledMinor, $settledCurrency),
            Money::ofMinor($nativeMinor, $nativeCurrency),
        ),
        'counterparty_name' => 'Kiosk',
        'counterparty_normalized' => 'kiosk',
        'normalization_version' => 1,
        'source_format' => 'ics_pdf',
        'import_run_id' => $run->id,
        'source_row_index' => random_int(1, 999999),
        'fingerprint' => str_pad(bin2hex(random_bytes(8)), 64, '0'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
    ]);

    return [$user, $tx];
}

// A yen has no minor unit and a euro has two, so the pair's minor-unit ratio
// is a hundred times its rate. The page printed that ratio.
it('quotes a yen charge at the rate a card statement would show', function (): void {
    [$user, $tx] = crossCurrencyRow(-10000, 'JPY', -5800, 'EUR');

    Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertSee('€0.00580 / JPY');
});

// settled_currency is whatever the statement settled in -- a PayPal balance
// held in dollars settles in dollars -- and the row hard-coded a euro sign.
it('names the currency the row actually settled in', function (): void {
    [$user, $tx] = crossCurrencyRow(-500, 'GBP', -640, 'USD');

    Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertSee('$1.280 / GBP');
});
