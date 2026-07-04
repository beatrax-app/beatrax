<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

/*
 * U-1 regression (browser UAT): the whole-transaction "Tag as tax-relevant"
 * path (HandlesTaxTagging → TagTransaction/UntagTransaction) must honour the
 * D-08 reconciled lock the same warn-first way the Ledger-side leg toggle
 * does (review WR-01, ReconciledLockGuardsTest::toggleLegTax). Before the fix
 * this parallel cross-module path wrote a tax_transaction_tags row to a
 * reconciled transaction with no guard.
 *
 * Driven through TransactionDetail — the trait consumer and the exact surface
 * UAT exercised (the tx detail page) — using the new Ledger Public
 * TransactionStatusQuery seam the Tax trait now consults.
 */

/**
 * Insert one transaction row and return its id. Mirrors the raw-insert
 * fixture idiom in TaxTagActionTest / ReconciledLockGuardsTest.
 *
 * @param  array<string, mixed>  $overrides
 */
function taxLockTx(int $userId, int $accountId, int $runId, array $overrides = []): int
{
    static $seq = 0;
    $seq++;

    return DB::table('transactions')->insertGetId(array_merge([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tax-lock-'.$seq.'-'.bin2hex(random_bytes(4))),
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
        'description' => 'Reconciled-lock tax fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $seq,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'tax-lock-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-tax-lock-fixture',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0000000009',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tax-lock-fixture.csv',
        'sha256' => str_pad('9', 64, '0'),
        'uploaded_at' => now(),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'previewed',
    ]);
});

it('tagTransaction refuses to tag a reconciled transaction (warn-first, no write)', function (): void {
    $txId = taxLockTx($this->user->id, $this->account->id, $this->run->id, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $txId])
        ->call('tagTransaction', $txId)
        ->assertDispatched('toast');

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(0);
});

it('saveTaxCategory refuses to write a reconciled transaction (warn-first, no write)', function (): void {
    $txId = taxLockTx($this->user->id, $this->account->id, $this->run->id, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $txId])
        ->set('taxPickerTxId', $txId)
        ->call('saveTaxCategory')
        ->assertDispatched('toast');

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(0);
});

it('untag refuses to remove the tax tag of a reconciled transaction (warn-first, no delete)', function (): void {
    $txId = taxLockTx($this->user->id, $this->account->id, $this->run->id, ['status' => 'reconciled']);

    DB::table('tax_transaction_tags')->insert([
        'user_id' => $this->user->id,
        'transaction_id' => $txId,
        'transaction_split_id' => null,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $txId])
        ->set('taxPickerTxId', $txId)
        ->call('untag')
        ->assertDispatched('toast');

    // The existing tag survives — the reconciled lock blocked the delete.
    expect(DB::table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(1);
});

it('applyBatchTag skips reconciled rows and tags only the editable siblings', function (): void {
    $cp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'gym-vendor-batch-fixture',
        'display_name' => 'Gym Vendor',
    ]);

    $reconciledId = taxLockTx($this->user->id, $this->account->id, $this->run->id, [
        'status' => 'reconciled',
        'counterparty_id' => $cp->id,
        'booked_at' => '2026-03-15 00:00:00',
    ]);
    $clearedId = taxLockTx($this->user->id, $this->account->id, $this->run->id, [
        'status' => 'cleared',
        'counterparty_id' => $cp->id,
        'booked_at' => '2026-03-20 00:00:00',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $clearedId])
        ->set('batchSuggestion', [
            'counterpartyId' => $cp->id,
            'counterpartyName' => 'Gym Vendor',
            'untaggedCount' => 2,
            'taxYear' => 2026,
        ])
        ->call('applyBatchTag')
        ->assertDispatched('toast');

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $reconciledId)->count())->toBe(0)
        ->and(DB::table('tax_transaction_tags')->where('transaction_id', $clearedId)->count())->toBe(1);
});
