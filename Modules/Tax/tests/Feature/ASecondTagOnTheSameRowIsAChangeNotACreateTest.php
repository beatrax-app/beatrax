<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Tax\Public\Actions\TagTransaction;

uses(RefreshDatabase::class);

// `PotAndTaxTagCaptureTest` already asserts that tagging a transaction reaches
// the log as a create and that untagging leaves a tombstone. It tags with a null
// category, a null note and a null year, and it never tags the same row twice.
//
// Both of those are where the history is. A create naming a row the peer already
// holds is discarded in silence, so announcing a re-tag as a create left two
// devices disagreeing for good — no quarantine, no error, nothing to see. And
// the note is a sealed column that travels as plaintext, because the writer
// seals it again under the current epoch; a tag carrying no values never
// exercises that.

function taxTagCaptureUser(): User
{
    return User::query()->create([
        'username' => 'tax-tag-capture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function bindTaxTagCaptureWriter(int $userId): void
{
    $keypair = sodium_crypto_sign_keypair();

    // SyncCaptureListener resolves the writer lazily from the container, so a
    // real one has to be bound or the capture throws instead of recording.
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'tax-tag-capture-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

/** @return array{user: User, transaction: Transaction, categoryId: int} */
function taxTagCaptureFixtures(): array
{
    $user = taxTagCaptureUser();

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN tax-tag-capture',
        'slug' => 'tax-tag-capture-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/tax-tag-capture.xml',
        'sha256' => hash('sha256', 'tax-tag-capture-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $transaction = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => -4200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4200,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Kamer van Koophandel',
        'counterparty_normalized' => 'kamer van koophandel',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('tax-tag-capture', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $categoryId = (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Chamber of commerce',
        'status' => 'active',
        'sort_order' => 1,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return ['user' => $user, 'transaction' => $transaction, 'categoryId' => $categoryId];
}

/** @return Collection<int, stdClass> */
function taxTagOps(int $userId)
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'tax_transaction_tags')
        ->get();
}

it('sends the values it was given, not just the row', function (): void {
    ['user' => $user, 'transaction' => $tx, 'categoryId' => $categoryId] = taxTagCaptureFixtures();
    bindTaxTagCaptureWriter((int) $user->id);

    $tagged = app(TagTransaction::class)
        ->execute((int) $user->id, (int) $tx->id, $categoryId, 'KvK annual fee', 2026);

    // The action refuses a movement that cannot carry a tag and answers false,
    // which would empty the log for a reason that is not a capture failure.
    expect($tagged)->toBeTrue('the fixture transaction was refused a tag, so nothing below is about capture');

    expect(taxTagOps((int) $user->id)->pluck('field')->all())
        ->toContain('user_id', 'transaction_id', 'deduction_category_id', 'note', 'tax_year_override');
});

// A create_row naming a row the peer already holds is discarded in silence, so
// announcing a re-tag as a create leaves the two devices disagreeing for good.
it('announces a re-tag as a change rather than a second create', function (): void {
    ['user' => $user, 'transaction' => $tx, 'categoryId' => $categoryId] = taxTagCaptureFixtures();

    app(TagTransaction::class)->execute((int) $user->id, (int) $tx->id, $categoryId, 'KvK annual fee', 2026);

    // Bound after the first write, so only the re-tag's ops are in view.
    bindTaxTagCaptureWriter((int) $user->id);

    app(TagTransaction::class)->execute((int) $user->id, (int) $tx->id, $categoryId, 'KvK annual fee, amended', 2026);

    expect(taxTagOps((int) $user->id)->pluck('op_type')->unique()->all())->toBe(['set']);
});
