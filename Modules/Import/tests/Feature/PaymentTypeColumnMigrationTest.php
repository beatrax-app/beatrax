<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->account = Account::create([
        'name' => 'ASN',
        'slug' => 'asn-ptype',
        'kind' => 'bank',
        'iban' => 'NL56ASNB9999900001',
        'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ptype.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

/**
 * Builds the column tuple a raw DB::table('transactions')->insert needs.
 * The trigger-rejection tests use this directly so the SQLite trigger
 * (not the PHP enum cast) is the layer asserted under test.
 *
 * @return array<string, mixed>
 */
function paymentTypeRowFixture(int $accountId, int $importRunId, int $rowIndex, string $paymentType, string $fingerprintSeed): array
{
    return [
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-05-25',
        'booked_at' => '2026-05-25 10:00:00',
        'value_date' => '2026-05-25',
        'amount_minor' => -100 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -100 - $rowIndex,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Fixture-'.$rowIndex,
        'counterparty_iban' => null,
        'counterparty_normalized' => 'fixture-'.$rowIndex,
        'normalization_version' => 1,
        'description' => 'Fixture row '.$rowIndex,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => $rowIndex,
        'source_ref' => null,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'z'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => $paymentType,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ];
}

it('adds the payment_type column to transactions', function (): void {
    expect(Schema::hasColumn('transactions', 'payment_type'))->toBeTrue();
});

it('defaults newly-inserted rows to payment_type = unknown when none specified', function (): void {
    $tx = Transaction::create([
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-25',
        'booked_at' => '2026-05-25 10:00:00',
        'value_date' => '2026-05-25',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'default-merchant',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 1,
        'fingerprint' => str_repeat('b', 64),
        'fingerprint_version' => 3,
    ]);

    $tx->refresh();
    expect($tx->payment_type)->toBe(PaymentType::Unknown);
});

it('accepts every valid payment_type enum value via raw INSERT', function (): void {
    $values = ['pin', 'online', 'transfer', 'direct_debit', 'cash', 'fee', 'refund', 'unknown'];
    foreach ($values as $i => $value) {
        DB::table('transactions')->insert(
            paymentTypeRowFixture($this->account->id, $this->importRun->id, $i, $value, 'val-'.$i),
        );
    }

    $rows = DB::table('transactions')->orderBy('source_row_index')->pluck('payment_type')->all();
    expect($rows)->toBe($values);
});

it('rejects an unknown payment_type value at the DB layer on INSERT', function (): void {
    expect(fn () => DB::table('transactions')->insert(
        paymentTypeRowFixture($this->account->id, $this->importRun->id, 99, 'made_up', 'bogus-insert'),
    ))->toThrow(QueryException::class, 'Invalid transactions.payment_type value');
});

it('rejects an unknown payment_type value on UPDATE', function (): void {
    DB::table('transactions')->insert(
        paymentTypeRowFixture($this->account->id, $this->importRun->id, 1000, 'pin', 'update-target'),
    );

    expect(fn () => DB::table('transactions')->where('source_row_index', 1000)->update([
        'payment_type' => 'whatever',
    ]))->toThrow(QueryException::class, 'Invalid transactions.payment_type value');
});
