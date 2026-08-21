<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->account = Account::create([
        'name' => 'ASN', 'slug' => 'asn', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('persists a transaction with a valid type', function (): void {
    $tx = Transaction::create([
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => -1234,
        'currency' => 'EUR',
        'settled_amount_minor' => -1234,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah amsterdam',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('a', 64),
        'fingerprint_version' => 1,
    ]);

    expect($tx->type)->toBe('expense');
});

it('rejects an invalid transaction type at the DB layer', function (): void {
    // Paired BEFORE INSERT/UPDATE triggers RAISE(ABORT), which the driver
    // surfaces as a QueryException on every write path, raw inserts included.
    expect(fn () => Transaction::create([
        'account_id' => $this->account->id,
        'type' => 'nonsense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => 0,
        'currency' => 'EUR',
        'settled_amount_minor' => 0,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => '_',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('b', 64),
        'fingerprint_version' => 1,
    ]))->toThrow(QueryException::class, 'Invalid transactions.type value');
});
