<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

// ApplyEnrichments stores each value JSON-encoded, so a numeric field decodes
// back to an int rather than a string; decodeScalar() has to coerce it instead
// of dropping the value.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->fixtureAccount = $seeded['account'];
});

it('coerces a JSON-encoded numeric stored value to its string form', function (): void {
    $run = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/conflict-query.dat',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $tx = Transaction::create([
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
        'counterparty_name' => 'Fixture',
        'counterparty_normalized' => 'fixture',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('c', 64),
        'fingerprint_version' => 1,
    ]);

    DB::table('pending_enrichment_conflicts')->insert([
        'user_id' => $this->fixtureUser->id,
        'transaction_id' => $tx->id,
        'field_name' => 'amount_minor',
        'stored_value' => json_encode(1299),
        'incoming_value' => json_encode('2000'),
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => $run->id,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var ReceiptConflictQuery $query */
    $query = $this->app->make(ReceiptConflictQuery::class);
    $result = $query->latestForUser($this->fixtureUser);

    expect($result)->not->toBeNull();
    expect($result['transactionId'])->toBe($tx->id);
    expect($result['field'])->toBe('amount_minor');
    expect($result['storedValue'])->toBe('1299');
    expect($result['incomingValue'])->toBe('2000');
    expect($result['sourceFormat'])->toBe('paypal-receipt');
});
