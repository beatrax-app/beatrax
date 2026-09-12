<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

// A restatement has a statement on both sides. The copy asked whether to prefer
// RECEIPTS in future, over a disagreement no receipt was part of, and offered a
// button reading "Use receipt" for a figure the bank sent.
beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->account = $seeded['account'];
    $this->actingAs($this->fixtureUser);

    $this->seedConflict = function (string $incomingSourceFormat): void {
        /** @var ImportRun $run */
        $run = ImportRun::create([
            'user_id' => $this->fixtureUser->id,
            'source_format' => $incomingSourceFormat,
            'raw_file_path' => '/tmp/toast-copy-'.$incomingSourceFormat.'.dat',
            'sha256' => hash('sha256', 'toast-copy-'.$incomingSourceFormat),
            'uploaded_at' => '2026-03-04 12:00:00',
            'status' => 'confirmed',
        ]);

        /** @var Account $account */
        $account = $this->account;

        /** @var Transaction $tx */
        $tx = Transaction::create([
            'user_id' => $this->fixtureUser->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => '2026-03-04',
            'booked_at' => '2026-03-04 00:00:00',
            'value_date' => '2026-03-04',
            'amount_minor' => -1299,
            'currency' => 'EUR',
            'settled_amount_minor' => -1299,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Cafe Rialto',
            'counterparty_normalized' => 'cafe rialto',
            'normalization_version' => 4,
            'description' => 'Cafe Rialto Utrecht',
            'source_format' => $incomingSourceFormat,
            'import_run_id' => $run->id,
            'source_row_index' => 0,
            'source_ref' => 'E2E-COFFEE-4471902',
            'fingerprint' => hash('sha256', 'toast-copy-'.$incomingSourceFormat),
            'fingerprint_version' => 4,
            'status' => 'cleared',
        ]);

        DB::table('pending_enrichment_conflicts')->insert([
            'user_id' => $this->fixtureUser->id,
            'transaction_id' => $tx->id,
            'field_name' => EnrichmentConflictField::AmountMinor->value,
            'stored_value' => '-1299',
            'incoming_value' => '-1499',
            'incoming_source_format' => $incomingSourceFormat,
            'import_run_id' => $run->id,
            'resolution' => null,
            'created_at' => '2026-03-04 12:00:00',
            'updated_at' => '2026-03-04 12:00:00',
        ]);
    };
});

it('calls a statement-side disagreement a restatement', function (): void {
    ($this->seedConflict)('asn-csv');

    expect($this->app->make(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser)['incomingIsReceipt'])
        ->toBeFalse();

    Livewire::test(ReceiptConflictToast::class)
        ->assertSet('restated', true)
        ->assertSee(Lang::get('receipts::messages.conflict.restated_title'))
        ->assertSee(Lang::get('receipts::messages.conflict.use_restated'))
        ->assertSee(Lang::get('receipts::messages.conflict.keep_stored'))
        ->assertDontSee(Lang::get('receipts::messages.conflict.title'))
        ->assertDontSee(Lang::get('receipts::messages.conflict.use_receipt'));
});

it('still names the receipt where one was sent', function (): void {
    ($this->seedConflict)(SourceFormat::Eml->value);

    expect($this->app->make(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser)['incomingIsReceipt'])
        ->toBeTrue();

    Livewire::test(ReceiptConflictToast::class)
        ->assertSet('restated', false)
        ->assertSee(Lang::get('receipts::messages.conflict.title'))
        ->assertSee(Lang::get('receipts::messages.conflict.use_receipt'))
        ->assertSee(Lang::get('receipts::messages.conflict.keep_statement'))
        ->assertDontSee(Lang::get('receipts::messages.conflict.restated_title'));
});
