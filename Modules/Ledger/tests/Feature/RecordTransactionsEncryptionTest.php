<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rt-encryption-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn-rt-enc', 'kind' => 'bank',
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

    // generateAndPersist() hard-throws without an unlocked app-lock KEK in the
    // session, and without an enabled keyring the write path stores plaintext
    // and every assertion below would pass for the wrong reason.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

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

    // Pin the content-column decrypt in the same test, so this file cannot go
    // green with the amounts intact but the encryption gone.
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    expect($codec->decryptRow('transactions', (array) $stored, (int) $this->user->id, $session)['description'])->toBe('Plaintext-amount fixture');
});
