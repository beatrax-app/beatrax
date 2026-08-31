<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Tax\Public\Services\TaxCategoryWriter;

uses(RefreshDatabase::class);

// The inline "new category" box swallowed a name clash whole: no toast, no
// inline line, the typed name still sitting in the field and the picker
// unchanged. Nothing told the reader the category they wanted already existed.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'tax-inline-duplicate',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(4));

    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Inline ASN '.$suffix,
        'slug' => 'inline-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/inline-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'inline-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->txId = DB::table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'inline-tx-'.$suffix),
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
        'description' => 'Inline category fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('says so when an inline category name is already taken', function (): void {
    app(TaxCategoryWriter::class)->add((int) $this->user->id, 'Studiekosten');

    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('pickerInlineNewName', 'Studiekosten')
        ->call('addInlineCategory')
        ->assertDispatched('toast', message: Lang::get('tax::messages.errors.name_duplicate'));
});

// The field keeps the name so the refusal has something to be about, and the
// panel stays open on the box the reader has to change.
it('leaves the rejected name in the box to be corrected', function (): void {
    app(TaxCategoryWriter::class)->add((int) $this->user->id, 'Studiekosten');

    Livewire::test(TransactionDetail::class, ['transactionId' => $this->txId])
        ->set('pickerIsNewCatOpen', true)
        ->set('pickerInlineNewName', 'Studiekosten')
        ->call('addInlineCategory')
        ->assertSet('pickerInlineNewName', 'Studiekosten')
        ->assertSet('pickerIsNewCatOpen', true);
});
