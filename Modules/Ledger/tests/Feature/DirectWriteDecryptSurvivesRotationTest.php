<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/*
 * DirectWriteDecryptSurvivesRotationTest — CRYPT-02 / BLOCKER-1: content
 * encrypted under epoch N via the direct-write (import) path still decrypts
 * to plaintext after current_epoch advances to N+1 — the codec tries every
 * keyring epoch (rotation-safe), since import rows have no per-row epoch
 * column the way op_log_entries.gdk_epoch does.
 * 14-VALIDATION.md CRYPT-02 row "Direct-write columns... decrypt after
 * rotation".
 *
 * RED until Plan 02 ships Modules\Sync\Public\Services\SensitiveColumnCodec
 * and Plan 05 ships Modules\Sync\Internal\Crypto\GdkRotationService. This
 * test references the planned production FQCN, which does not yet exist —
 * the failure is "class not found", the correct Wave 0 RED state.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'direct-write-rotation-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn-dwr', 'kind' => 'asn',
        'iban' => 'NL57ASNB0123456781', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dwr.csv',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
});

it('a transaction encrypted under epoch N still decrypts after the GDK rotates to epoch N+1', function (): void {
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $keyring->generateAndPersist((int) $this->user->id, $session);

    $action = $this->app->make(RecordTransactions::class);
    $action([
        $this->canonical([
            'userId' => $this->user->id,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
            'description' => 'Groceries before rotation',
            'counterpartyName' => 'Pre-Rotation Merchant',
        ]),
    ], $this->user);

    // Force a rotation via a removed-device fixture — epoch advances to N+1.
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $removedId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $this->user->id,
        'device_id' => 'dwr-removed-device',
        'name' => 'Removed',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);
    $rotation->rotateAndRevoke((int) $this->user->id, $removedId);

    $stored = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $decrypted = $codec->decryptRow('transactions', (array) $stored);

    expect($decrypted['description'])->toBe('Groceries before rotation');
    expect($decrypted['counterparty_name'])->toBe('Pre-Rotation Merchant');
});
