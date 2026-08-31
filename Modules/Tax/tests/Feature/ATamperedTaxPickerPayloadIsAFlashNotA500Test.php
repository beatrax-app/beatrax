<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;

uses(RefreshDatabase::class);

function taxTamperTransaction(int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Tamper ASN '.$suffix,
        'slug' => 'tamper-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tamper-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tamper-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tamper-tx-'.$suffix),
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 00:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'gym-vendor',
        'counterparty_name' => 'Gym Vendor BV',
        'normalization_version' => 1,
        'description' => 'Tampered picker fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'tax-picker-tamper',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
    $this->txId = taxTamperTransaction($this->user->id);
});

// Every one of these is a /livewire/update round trip a tampered client can
// make; the guards behind them are correct and answer by throwing, so what is
// under test is that the answer reaches the reader as a flash.
it('flashes rather than 500s on an out-of-range year override', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('taxPickerTxId', $this->txId)
        ->set('pickerYearOverride', 9999)
        ->call('saveTaxCategory')
        ->assertDispatched('toast');

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $this->txId)->exists())->toBeFalse();
});

it('flashes rather than 404s on a deduction category the user does not have', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('taxPickerTxId', $this->txId)
        ->set('pickerCategoryId', 987654)
        ->call('saveTaxCategory')
        ->assertDispatched('toast');

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $this->txId)->exists())->toBeFalse();
});

it('drops a batch banner whose payload has no counterparty id at all', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('batchSuggestion', ['zzz' => 1])
        ->call('applyBatchTag')
        ->assertSet('batchSuggestion', null);
});

it('drops a batch banner whose counterparty id is not an id', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('batchSuggestion', ['counterpartyId' => 'zzz', 'counterpartyName' => 'x', 'untaggedCount' => 4])
        ->call('applyBatchTag')
        ->assertSet('batchSuggestion', null);
});
