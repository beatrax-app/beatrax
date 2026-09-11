<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

// A tampered /livewire/update round trip is answered in one of two places. A
// property the reader's own template writes reaches the handler, whose guard
// throws and whose answer must arrive as a flash. One only the server writes is
// refused at the boundary by #[Locked], and never reaches a guard at all.
// tagTransaction() opens the picker and writes the bare tag in one call, which
// is the only way a reader reaches the picker now that taxPickerTxId is locked.
// So the row exists before the tampered save, and what must not move is what it
// carries.
function taxTamperRow(int $txId): ?object
{
    return DB::table('tax_transaction_tags')->where('transaction_id', $txId)->first();
}

it('flashes rather than 500s on an out-of-range year override', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->call('tagTransaction', $this->txId)
        ->set('pickerYearOverride', 9999)
        ->call('saveTaxCategory')
        ->assertDispatched('toast');

    expect(taxTamperRow($this->txId)?->tax_year_override)->toBeNull();
});

it('flashes rather than 404s on a deduction category the user does not have', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->call('tagTransaction', $this->txId)
        ->set('pickerCategoryId', 987654)
        ->call('saveTaxCategory')
        ->assertDispatched('toast');

    expect(taxTamperRow($this->txId)?->deduction_category_id)->toBeNull();
});

// The positive control for the pair above: the same round trip with a category
// the reader does have writes it, so "unchanged" is a refusal rather than a
// save path that never works.
it('writes a deduction category the reader does have', function (): void {
    $categoryId = (int) DB::table('tax_deduction_categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Office supplies',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->call('tagTransaction', $this->txId)
        ->set('pickerCategoryId', $categoryId)
        ->call('saveTaxCategory');

    expect(taxTamperRow($this->txId)?->deduction_category_id)->toBe($categoryId);
});

// The banner is built by the server and named by no template, so the payload
// these two used to hand applyBatchTag can no longer be handed to it. The
// parsing behind it still stands; what is pinned here is that the round trip
// carrying it is refused before any of that runs.
it('refuses a batch banner payload with no counterparty id at all', function (): void {
    expect(fn () => Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('batchSuggestion', ['zzz' => 1]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses a batch banner payload whose counterparty id is not an id', function (): void {
    expect(fn () => Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('batchSuggestion', ['counterpartyId' => 'zzz', 'counterpartyName' => 'x', 'untaggedCount' => 4]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
