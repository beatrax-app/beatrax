<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\DeletesTransaction;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

// The field defect this reproduces: a transaction deleted on one device took
// its occurrence with it at the database, wrote no tombstone, and the peer
// quarantined the occurrence's still-live create op as missing_reference.

function tombstoneFor(string $table, int|string $pk): ?object
{
    return DB::table('op_log_entries')
        ->where('table_name', $table)
        ->where('pk', (string) $pk)
        ->where('op_type', 'delete_tombstone')
        ->first();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-04 10:00:00');

    $this->user = User::create([
        'username' => 'cascade-tombstone-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $keypair = sodium_crypto_sign_keypair();

    // Bound into the container because SyncCaptureListener resolves its writer
    // lazily and would otherwise never see a real one.
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-a',
        'userId' => $this->user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);
    app()->instance(OpLogWriter::class, $writer);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN cascade',
        'slug' => 'cascade-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => 'fixtures/cascade.xml',
        'sha256' => hash('sha256', 'cascade'.bin2hex(random_bytes(4))),
        'uploaded_at' => '2026-09-01 09:00:00',
        'status' => 'confirmed',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes a tombstone for an occurrence the deleted transaction owned', function (): void {
    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-09-01',
        'booked_at' => '2026-09-01 12:00:00',
        'value_date' => '2026-09-01',
        'amount_minor' => -6521,
        'currency' => 'EUR',
        'settled_amount_minor' => -6521,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'DOMINOS',
        'counterparty_normalized' => 'dominos',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('cascade', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $seriesId = DB::table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'DOMINOS',
        'latest_amount_minor' => -6521,
        'latest_currency' => 'EUR',
        'cluster_key' => 'expense::cascade::eur::monthly',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $occurrenceId = DB::table('recurring_series_occurrences')->insertGetId([
        'user_id' => $this->user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transaction->id,
        'observed_at' => '2026-09-01',
        'observed_amount_minor' => -6521,
        'observed_currency' => 'EUR',
    ]);

    $categoryId = DB::table('categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Takeaway',
        'slug' => 'takeaway-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
    ]);

    $legId = DB::table('transaction_splits')->insertGetId([
        'user_id' => $this->user->id,
        'transaction_id' => $transaction->id,
        'category_id' => $categoryId,
        'settled_amount_minor' => -6521,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
    ]);

    app(DeletesTransaction::class)->delete($this->user, $transaction->id);

    // The leg was already tombstoned before this change; the occurrence was
    // not, and both are the transaction's to announce.
    expect(tombstoneFor('transaction_splits', $legId))->not->toBeNull();

    $tombstone = DB::table('op_log_entries')
        ->where('user_id', $this->user->id)
        ->where('table_name', 'recurring_series_occurrences')
        ->where('pk', (string) $occurrenceId)
        ->where('op_type', 'delete_tombstone')
        ->first();

    expect($tombstone)->not->toBeNull(
        'the occurrence went with its transaction and said nothing, which is what a peer quarantines',
    );

    expect(DB::table('recurring_series_occurrences')->where('id', $occurrenceId)->exists())->toBeFalse();

    // The series outlives the transaction: it is not the transaction's to take.
    expect(DB::table('recurring_series')->where('id', $seriesId)->exists())->toBeTrue();
});
