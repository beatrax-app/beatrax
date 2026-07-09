<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/*
 * EncryptionMigrationNoteBackfillTest — D-08 (14.1-03-PLAN.md Task 1): the
 * enable-time migration sweep (EncryptionMigrationService::migrate()) now
 * also covers PRE-EXISTING plaintext tax_transaction_tags.note /
 * transaction_splits.note rows — history written before the 14.1-02
 * write-side encrypt hooks landed. Idempotent: re-running never
 * double-encrypts (AEAD-verify guard via alreadyEncryptedProjectionValue()).
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'note-backfill-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-note-backfill',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0123456799',
        'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/note-backfill.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
    $this->transaction = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1200,
        'currency' => 'EUR',
        'settled_amount_minor' => -1200,
        'settled_currency' => 'EUR',
        'description' => 'note backfill fixture',
        'counterparty_name' => 'Note Backfill Merchant',
        'counterparty_normalized' => 'note backfill merchant',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('b', 64),
        'fingerprint_version' => 1,
    ]);
    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Note Backfill Category',
        'slug' => 'note-backfill-category',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

it('encrypts pre-existing plaintext tax_transaction_tags.note and transaction_splits.note rows idempotently on the enable-time migration', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Simulate rows written BEFORE the 14.1-02 write-side encrypt hooks
    // landed — plain raw inserts, mirroring what an older app version
    // would have left on disk.
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $this->user->id,
        'transaction_id' => $this->transaction->id,
        'deduction_category_id' => null,
        'tax_year_override' => null,
        'note' => 'pre-existing tax note',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $db->connection()->table('transaction_splits')->insert([
        'user_id' => $this->user->id,
        'transaction_id' => $this->transaction->id,
        'category_id' => $this->category->id,
        'settled_amount_minor' => -1200,
        'settled_currency' => 'EUR',
        'note' => 'pre-existing split note',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);
    $migration->migrate($this->user, $session);

    $tagRow = $db->connection()->table('tax_transaction_tags')->where('transaction_id', $this->transaction->id)->first();
    $splitRow = $db->connection()->table('transaction_splits')->where('transaction_id', $this->transaction->id)->first();

    expect($tagRow->note)->not->toBe('pre-existing tax note');
    expect($splitRow->note)->not->toBe('pre-existing split note');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    expect($codec->decryptValue('tax_transaction_tags', 'note', $tagRow->note, $this->user->id, $session)['value'])
        ->toBe('pre-existing tax note');
    expect($codec->decryptValue('transaction_splits', 'note', $splitRow->note, $this->user->id, $session)['value'])
        ->toBe('pre-existing split note');

    // Idempotency: re-running migrate() again must never double-encrypt —
    // the stored ciphertext stays byte-for-byte stable and still decrypts
    // to the original plaintext (migrate() itself is a no-op once
    // current_epoch is set, and the AEAD-verify guard in
    // alreadyEncryptedProjectionValue() protects any future resumed pass
    // that reaches the sweep again).
    $tagCiphertextBefore = $tagRow->note;
    $splitCiphertextBefore = $splitRow->note;

    $migration->migrate($this->user, $session);

    $tagRowAfter = $db->connection()->table('tax_transaction_tags')->where('transaction_id', $this->transaction->id)->first();
    $splitRowAfter = $db->connection()->table('transaction_splits')->where('transaction_id', $this->transaction->id)->first();

    expect($tagRowAfter->note)->toBe($tagCiphertextBefore);
    expect($splitRowAfter->note)->toBe($splitCiphertextBefore);

    expect($codec->decryptValue('tax_transaction_tags', 'note', $tagRowAfter->note, $this->user->id, $session)['value'])
        ->toBe('pre-existing tax note');
    expect($codec->decryptValue('transaction_splits', 'note', $splitRowAfter->note, $this->user->id, $session)['value'])
        ->toBe('pre-existing split note');
});
