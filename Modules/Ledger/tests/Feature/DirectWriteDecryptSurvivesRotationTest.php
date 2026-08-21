<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

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

    // The keyring calls hard-throw without an unlocked app-lock KEK in session.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
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

    // Appending an epoch advances current_epoch to N+1. Import rows carry no
    // per-row epoch column, so the codec has to try every one of them.
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
