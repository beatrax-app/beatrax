<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;

// prefer_receipt rewrites the very columns the fingerprint is composed over.
// The fingerprint is the dedup key, so a rewrite that leaves the stored digest
// behind makes the row unreachable by its own re-import: the next receipt
// carrying the same values hashes to something no stored row claims, and lands
// a second time.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];

    $this->fingerprints = $this->app->make(FingerprintComposer::class);
    $this->counterpartyKey = $this->app->make(CounterpartyKey::class);

    $this->postedAt = '2026-04-01';
    $this->bookedAt = '2026-04-01 12:00:00';

    $this->composeFor = function (int $amountMinor, string $currency, ?string $counterpartyName): string {
        return $this->fingerprints->composeTuple(
            $this->fixtureUser->id,
            $this->fixtureAccount->id,
            $this->postedAt,
            $this->bookedAt,
            $amountMinor,
            $currency,
            $this->counterpartyKey->forName($counterpartyName, $this->fixtureUser->id),
        );
    };

    $this->seedConflict = function (string $field, mixed $stored, mixed $incoming) {
        static $idx = 0;
        $idx++;

        $run = ImportRun::create([
            'user_id' => $this->fixtureUser->id,
            'source_format' => 'paypal-csv',
            'raw_file_path' => '/tmp/recompose-'.$idx.'.dat',
            'sha256' => str_pad((string) $idx, 64, 'f', STR_PAD_LEFT),
            'uploaded_at' => CarbonImmutable::now(),
            'status' => 'confirmed',
        ]);

        $storedName = $field === 'counterparty_name' && is_string($stored) ? $stored : 'Stored Merchant';

        $tx = Transaction::create([
            'user_id' => $this->fixtureUser->id,
            'account_id' => $this->fixtureAccount->id,
            'type' => 'expense',
            'posted_at' => $this->postedAt,
            'booked_at' => $this->bookedAt,
            'value_date' => $this->postedAt,
            'amount_minor' => $field === 'amount_minor' && is_int($stored) ? $stored : -2500,
            'currency' => $field === 'currency' && is_string($stored) ? $stored : 'EUR',
            'settled_amount_minor' => -2500,
            'settled_currency' => 'EUR',
            'counterparty_name' => $storedName,
            'counterparty_normalized' => $this->counterpartyKey->forName($storedName, $this->fixtureUser->id),
            'normalization_version' => $this->fingerprints->version(),
            'source_format' => 'paypal-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $idx,
            'fingerprint' => ($this->composeFor)(
                $field === 'amount_minor' && is_int($stored) ? $stored : -2500,
                $field === 'currency' && is_string($stored) ? $stored : 'EUR',
                $storedName,
            ),
            'fingerprint_version' => $this->fingerprints->version(),
        ]);

        $this->conflictId = (int) DB::table('pending_enrichment_conflicts')->insertGetId([
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

        return $tx;
    };

    $this->preferReceipt = function (): void {
        /** @var ApplyReceiptConflictResolution $resolve */
        $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
        $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $this->conflictId);
    };
});

it('leaves a rewritten counterparty_name hashing to the fingerprint it stores, so a re-import still recognises the row', function (): void {
    $tx = ($this->seedConflict)('counterparty_name', 'Stored Merchant', 'Receipt Merchant BV');

    ($this->preferReceipt)();

    $stored = DB::table('transactions')->where('id', $tx->id)->first();

    expect($stored->fingerprint)->toBe(($this->composeFor)(-2500, 'EUR', 'Receipt Merchant BV'));
    expect($stored->fingerprint_version)->toBe($this->fingerprints->version());
});

it('re-keys counterparty_normalized from the incoming plaintext rather than from the sealed ciphertext', function (): void {
    $tx = ($this->seedConflict)('counterparty_name', 'Stored Merchant', 'Receipt Merchant BV');

    ($this->preferReceipt)();

    $stored = DB::table('transactions')->where('id', $tx->id)->first();

    expect($stored->counterparty_normalized)
        ->toBe($this->counterpartyKey->forName('Receipt Merchant BV', $this->fixtureUser->id));
    expect($stored->normalization_version)->toBe($this->fingerprints->version());
});

it('recomposes the fingerprint over the incoming amount_minor and not the amount it replaced', function (): void {
    $tx = ($this->seedConflict)('amount_minor', -2500, -3199);

    ($this->preferReceipt)();

    $stored = DB::table('transactions')->where('id', $tx->id)->first();

    expect($stored->amount_minor)->toBe(-3199);
    expect($stored->fingerprint)->toBe(($this->composeFor)(-3199, 'EUR', 'Stored Merchant'));
});

it('recomposes the fingerprint over the incoming currency and not the currency it replaced', function (): void {
    $tx = ($this->seedConflict)('currency', 'EUR', 'USD');

    ($this->preferReceipt)();

    $stored = DB::table('transactions')->where('id', $tx->id)->first();

    expect($stored->currency)->toBe('USD');
    expect($stored->fingerprint)->toBe(($this->composeFor)(-2500, 'USD', 'Stored Merchant'));
});

it('leaves the fingerprint alone for a description conflict, which is not a term of the tuple', function (): void {
    $tx = ($this->seedConflict)('description', 'Statement text', 'Receipt text');
    $before = DB::table('transactions')->where('id', $tx->id)->value('fingerprint');

    ($this->preferReceipt)();

    $stored = DB::table('transactions')->where('id', $tx->id)->first();

    expect($stored->fingerprint)->toBe($before);
    expect($stored->fingerprint_version)->toBe($this->fingerprints->version());
});
