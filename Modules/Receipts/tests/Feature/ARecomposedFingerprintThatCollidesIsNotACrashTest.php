<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;

// Recomposing the tuple can land on one the ledger already holds -- which is
// not a failure but an answer: this receipt describes a transaction already
// there. Thrown out of the request instead, the reader gets a toast on a
// conflict they then cannot clear, and the same button throws again.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];
    $this->actingAs($this->fixtureUser);

    $this->fingerprints = $this->app->make(FingerprintComposer::class);
    $this->counterpartyKey = $this->app->make(CounterpartyKey::class);

    $this->normalized = $this->counterpartyKey->forName('Stored Merchant', $this->fixtureUser->id);

    $this->composeFor = fn (int $amountMinor): string => $this->fingerprints->composeTuple(
        $this->fixtureUser->id,
        $this->fixtureAccount->id,
        '2026-04-01',
        '2026-04-01 12:00:00',
        $amountMinor,
        'EUR',
        $this->normalized,
    );

    $this->seedTransaction = function (int $amountMinor): Transaction {
        static $idx = 0;
        $idx++;

        $run = ImportRun::create([
            'user_id' => $this->fixtureUser->id,
            'source_format' => 'paypal-csv',
            'raw_file_path' => '/tmp/collision-'.$idx.'.dat',
            'sha256' => str_pad((string) $idx, 64, 'c', STR_PAD_LEFT),
            'uploaded_at' => CarbonImmutable::now(),
            'status' => 'confirmed',
        ]);

        return Transaction::create([
            'user_id' => $this->fixtureUser->id,
            'account_id' => $this->fixtureAccount->id,
            'type' => 'expense',
            'posted_at' => '2026-04-01',
            'booked_at' => '2026-04-01 12:00:00',
            'value_date' => '2026-04-01',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Stored Merchant',
            'counterparty_normalized' => $this->normalized,
            'normalization_version' => $this->fingerprints->version(),
            'source_format' => 'paypal-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $idx,
            'fingerprint' => ($this->composeFor)($amountMinor),
            'fingerprint_version' => $this->fingerprints->version(),
        ]);
    };

    $this->seedConflict = fn (Transaction $tx, int $stored, int $incoming): int => (int) DB::table('pending_enrichment_conflicts')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'transaction_id' => $tx->id,
        'field_name' => 'amount_minor',
        'stored_value' => json_encode($stored),
        'incoming_value' => json_encode($incoming),
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
});

it('treats a recomposed fingerprint the ledger already holds as the same transaction, not as an exception', function (): void {
    $twin = ($this->seedTransaction)(-3199);
    $subject = ($this->seedTransaction)(-2500);
    $conflictId = ($this->seedConflict)($subject, -2500, -3199);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
    $resolved = $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $conflictId);

    expect($resolved)->toBe(1);

    $row = DB::table('transactions')->where('id', $subject->id)->first();
    expect((int) $row->amount_minor)->toBe(-2500);
    expect($row->fingerprint)->toBe(($this->composeFor)(-2500));

    expect(DB::table('transactions')->where('id', $twin->id)->value('amount_minor'))->toBe(-3199);
});

it('clears the conflict on a collision, so the toast the reader pressed does not come back with the same button', function (): void {
    ($this->seedTransaction)(-3199);
    $subject = ($this->seedTransaction)(-2500);
    $conflictId = ($this->seedConflict)($subject, -2500, -3199);

    Livewire::test(ReceiptConflictToast::class)
        ->assertSet('visible', true)
        ->call('useReceipt')
        ->assertSet('visible', false);

    expect(DB::table('pending_enrichment_conflicts')->where('id', $conflictId)->count())->toBe(0);

    Livewire::test(ReceiptConflictToast::class)->assertSet('visible', false);
});
