<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/*
 * RecordTransactionsEncryptionTest — CRYPT-01: importing a CanonicalTransaction
 * encrypts description/counterparty_name/counterparty_iban/raw_payload
 * (D-02b direct-write set) before insertOrIgnore, and the read-side decrypt
 * (via the Sync Public SensitiveColumnCodec) returns the original plaintext.
 * 14-VALIDATION.md CRYPT-01 row 3 (Pitfall 2: the direct-write path bypasses
 * the op-log entirely per SYNC-03 — this is a SEPARATE hook from Plan 03's
 * op-log encryption).
 *
 * Encryption must be explicitly turned on for the user (GdkKeyringService::
 * generateAndPersist) before importing — RecordTransactions::persistChunk
 * is a pass-through (plaintext at rest) when encryption is not enabled,
 * exactly like every other SensitiveColumnCodec consumer.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rt-encryption-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn-rt-enc', 'kind' => 'asn',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rt-enc.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    // Prime the session with an unlocked dummy app-lock KEK — mirrors
    // Modules/Sync/tests/TestCase.php's own priming. Without this,
    // GdkKeyringService::generateAndPersist() below hard-throws
    // LogicException (D-02 weak-key-window guard).
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $this->user->id, $session);
});

it('encrypts description/counterparty_name/counterparty_iban at rest and decrypts back to the original plaintext', function (): void {
    $action = $this->app->make(RecordTransactions::class);

    $row = $this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
        'description' => 'Albert Heijn weekly groceries',
        'counterpartyName' => 'Albert Heijn',
        'counterpartyIban' => 'NL91ABNA0417164300',
    ]);

    $action([$row], $this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->first();

    // Ciphertext at rest — the plaintext must NOT be readable directly off disk.
    expect($stored->description)->not->toBe('Albert Heijn weekly groceries');
    expect($stored->counterparty_name)->not->toBe('Albert Heijn');
    expect($stored->counterparty_iban)->not->toBe('NL91ABNA0417164300');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $decrypted = $codec->decryptRow('transactions', (array) $stored, (int) $this->user->id, $session);

    expect($decrypted['description'])->toBe('Albert Heijn weekly groceries');
    expect($decrypted['counterparty_name'])->toBe('Albert Heijn');
    expect($decrypted['counterparty_iban'])->toBe('NL91ABNA0417164300');
});

it('leaves amount_minor/settled_amount_minor plaintext (D-02a) while the content columns route through the Sync codec', function (): void {
    $action = $this->app->make(RecordTransactions::class);

    $row = $this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
        'amountMinor' => -1299,
        'settledAmountMinor' => -1299,
        'description' => 'Plaintext-amount fixture',
    ]);

    $action([$row], $this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->first();

    expect((int) $stored->amount_minor)->toBe(-1299);
    expect((int) $stored->settled_amount_minor)->toBe(-1299);

    // D-02a is only meaningful once D-02b's content-column encryption
    // actually exists — pin both halves of the boundary in the same test so
    // this file cannot go accidentally green before the codec ships.
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    expect($codec->decryptRow('transactions', (array) $stored, (int) $this->user->id, $session)['description'])->toBe('Plaintext-amount fixture');
});
