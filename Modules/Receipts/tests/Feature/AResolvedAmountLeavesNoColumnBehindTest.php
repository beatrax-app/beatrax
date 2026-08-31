<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Sync\Public\Events\TransactionMutated;

// A transactions row records one amount twice: the native pair the fingerprint
// is composed over, and the settled pair every balance, budget and forecast
// sums. Moving one and leaving the other makes the row disagree with its own
// dedup key -- the fingerprint saying 31.99 while the whole app reads 25.00.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];

    $this->fingerprints = $this->app->make(FingerprintComposer::class);
    $this->counterpartyKey = $this->app->make(CounterpartyKey::class);

    $this->seedAmountConflict = function (array $txOverrides, string $field, mixed $stored, mixed $incoming): array {
        static $idx = 0;
        $idx++;

        $run = ImportRun::create([
            'user_id' => $this->fixtureUser->id,
            'source_format' => 'paypal-csv',
            'raw_file_path' => '/tmp/amount-identity-'.$idx.'.dat',
            'sha256' => str_pad((string) $idx, 64, 'a', STR_PAD_LEFT),
            'uploaded_at' => CarbonImmutable::now(),
            'status' => 'confirmed',
        ]);

        $tx = Transaction::create(array_merge([
            'user_id' => $this->fixtureUser->id,
            'account_id' => $this->fixtureAccount->id,
            'type' => 'expense',
            'posted_at' => '2026-04-01',
            'booked_at' => '2026-04-01 12:00:00',
            'value_date' => '2026-04-01',
            'amount_minor' => -2500,
            'currency' => 'EUR',
            'settled_amount_minor' => -2500,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Stored Merchant',
            'counterparty_normalized' => $this->counterpartyKey->forName('Stored Merchant', $this->fixtureUser->id),
            'normalization_version' => $this->fingerprints->version(),
            'source_format' => 'paypal-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $idx,
            'fingerprint' => str_pad((string) $idx, 64, 'a', STR_PAD_LEFT),
            'fingerprint_version' => $this->fingerprints->version(),
        ], $txOverrides));

        $conflictId = (int) DB::table('pending_enrichment_conflicts')->insertGetId([
            'user_id' => $this->fixtureUser->id,
            'transaction_id' => $tx->id,
            'field_name' => $field,
            'stored_value' => json_encode($stored),
            'incoming_value' => json_encode($incoming),
            'incoming_source_format' => 'paypal-receipt',
            'import_run_id' => $run->id,
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);

        return ['tx' => $tx, 'conflict_id' => $conflictId];
    };

    $this->preferReceipt = function (int $conflictId): void {
        /** @var ApplyReceiptConflictResolution $resolve */
        $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
        $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $conflictId);
    };
});

it('moves settled_amount_minor with amount_minor, so the ledger reads the figure its fingerprint was composed over', function (): void {
    $seeded = ($this->seedAmountConflict)([], 'amount_minor', -2500, -3199);

    ($this->preferReceipt)($seeded['conflict_id']);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();

    expect((int) $row->amount_minor)->toBe(-3199);
    expect((int) $row->settled_amount_minor)->toBe(-3199);
});

it('moves settled_currency with currency on a single-currency row', function (): void {
    $seeded = ($this->seedAmountConflict)([], 'currency', 'EUR', 'USD');

    ($this->preferReceipt)($seeded['conflict_id']);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();

    expect($row->currency)->toBe('USD');
    expect($row->settled_currency)->toBe('USD');
});

// A cross-currency row's settled leg is the bank's own conversion, which no
// receipt restates -- so it stands, and the stored rate is re-derived from the
// pair it now sits beside rather than left describing the amount it replaced.
it('leaves a cross-currency settled leg alone and re-derives the rate it is stored beside', function (): void {
    $seeded = ($this->seedAmountConflict)([
        'amount_minor' => -3000,
        'currency' => 'USD',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.83333333',
    ], 'amount_minor', -3000, -3600);

    ($this->preferReceipt)($seeded['conflict_id']);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();

    expect((int) $row->amount_minor)->toBe(-3600);
    expect((int) $row->settled_amount_minor)->toBe(-2500);
    expect((string) $row->fx_rate_used)->toBe('0.69444444');
});

// The op log the paired device replays has to carry every column the write
// moved: a peer that replays amount_minor alone holds the same disagreement.
it('names every amount column it wrote in the mutation the peer replays', function (): void {
    $seeded = ($this->seedAmountConflict)([], 'amount_minor', -2500, -3199);

    $captured = [];
    Event::listen(
        TransactionMutated::class,
        function (TransactionMutated $event) use (&$captured): void {
            $captured = $event->dirtyFields;
        },
    );

    ($this->preferReceipt)($seeded['conflict_id']);

    expect(array_keys($captured))->toContain('amount_minor', 'settled_amount_minor', 'fingerprint');
    expect($captured['settled_amount_minor'])->toBe(-3199);
});
