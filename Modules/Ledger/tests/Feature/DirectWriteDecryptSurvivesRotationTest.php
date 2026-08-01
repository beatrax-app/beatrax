<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
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
 * Rewritten per 14-04-PLAN.md Task 1: simulates a rotation directly via
 * GdkKeyringService::appendEpoch (the mechanism Plan 05's
 * GdkRotationService::rotateAndRevoke will itself call) rather than
 * depending on the not-yet-built GdkRotationService/device-revocation
 * flow, which is out of this plan's scope.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'direct-write-rotation-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn-dwr', 'kind' => 'bank',
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

    // Prime the session with an unlocked dummy app-lock KEK — mirrors
    // Modules/Sync/tests/TestCase.php's own priming.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));
});

it('a transaction encrypted under epoch N still decrypts after the GDK rotates to epoch N+1', function (): void {
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $epoch1 = $keyring->generateAndPersist((int) $this->user->id, $session);

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

    // Simulate a rotation (what Plan 05's GdkRotationService::rotateAndRevoke
    // will do on device removal): append a new epoch, advancing
    // sync_encryption_state.current_epoch to N+1. The OLD epoch-1 ciphertext
    // must still decrypt (try-every-epoch, BLOCKER-1).
    $epoch2 = new GdkEpoch(epochId: $epoch1->epochId + 1, keyHex: bin2hex(random_bytes(32)));
    $keyring->appendEpoch((int) $this->user->id, $epoch2, $session);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $decrypted = $codec->decryptRow('transactions', (array) $stored, (int) $this->user->id, $session);

    expect($decrypted['description'])->toBe('Groceries before rotation');
    expect($decrypted['counterparty_name'])->toBe('Pre-Rotation Merchant');
});
