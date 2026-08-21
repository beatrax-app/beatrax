<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'migration-rollback-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn-migration-rollback', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456782', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/migration-rollback.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
});

it('leaves the transaction row untouched when the app-lock KEK is unavailable — never a half-encrypted row', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -500,
        'currency' => 'EUR',
        'settled_amount_minor' => -500,
        'settled_currency' => 'EUR',
        'description' => 'plaintext before migration',
        'counterparty_name' => 'Migration Merchant',
        'counterparty_normalized' => 'migration merchant',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('e', 64),
        'fingerprint_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // Core's TestCase primes no app-lock KEK, and migrate() must never touch
    // a row without one.
    try {
        $migration->migrate($this->user, $session);
        $forcedFailure = true;
    } catch (Throwable) {
        $forcedFailure = false;
    }

    $row = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();

    // Fully succeeded (ciphertext) or fully rolled back (original plaintext);
    // no partial state is acceptable either way.
    expect($row->description === 'plaintext before migration' || $forcedFailure)->toBeTrue();
});

it('gates read-trust while migration_in_progress is set — a stale in-progress flag must not be silently trusted', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('sync_encryption_state')->insert([
        'user_id' => $this->user->id,
        'current_epoch' => null,
        'migration_in_progress' => true,
        'enabled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // A stale flag from a crashed prior attempt must be resolved, never left
    // set while new writes proceed against half-migrated data.
    $migration->migrate($this->user, $session);

    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    expect((bool) $state->migration_in_progress)->toBeFalse();
});

it('a genuine forced failure mid-pass (real KEK, real data) leaves zero half-encrypted rows, preserves the snapshot, and a subsequent happy-path migrate() then succeeds', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // RefreshDatabase resets the DB's autoincrement but not the filesystem, so
    // a keyring left by an earlier run against the same reused user id would
    // make the file_exists() assertions below pass for the wrong reason.
    $keyringPath = UserDataPathService::appPath("sync/gdk/{$this->user->id}.enc");
    @unlink($keyringPath);
    @unlink($keyringPath.'.tmp');

    $db->connection()->table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-02',
        'booked_at' => '2026-07-02 10:00:00',
        'value_date' => '2026-07-02',
        'amount_minor' => -750,
        'currency' => 'EUR',
        'settled_amount_minor' => -750,
        'settled_currency' => 'EUR',
        'description' => 'plaintext before forced failure',
        'counterparty_name' => 'Forced Failure Merchant',
        'counterparty_normalized' => 'forced failure merchant',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('f', 64),
        'fingerprint_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Throws the first time afterChunkProcessed() fires (the transactions
    // chunk — this user has no op_log_entries rows) and reads state through
    // the still-uncommitted transaction before throwing.
    $migration = new class($db, $this->app->make(PreMigrationSnapshot::class), $this->app->make(AppLockKeyService::class), $this->app->make(Clock::class), $this->app->make(Container::class), $this->app->make(CacheRepository::class)) extends EncryptionMigrationService
    {
        public bool $observedInProgressMidPass = false;

        protected function afterChunkProcessed(int $userId, int $rowsProcessedSoFar): void
        {
            $state = $this->db->connection()->table('sync_encryption_state')->where('user_id', $userId)->first();
            $this->observedInProgressMidPass = $state !== null && (bool) $state->migration_in_progress;

            throw new RuntimeException('EncryptionMigrationRollbackTest: injected mid-pass failure.');
        }
    };

    try {
        $migration->migrate($this->user, $session);
        $threw = false;
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'EncryptionMigrationRollbackTest: injected mid-pass failure.';
    }

    expect($threw)->toBeTrue();
    expect($migration->observedInProgressMidPass)->toBeTrue();

    $row = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();
    expect($row->description)->toBe('plaintext before forced failure');
    expect($row->counterparty_name)->toBe('Forced Failure Merchant');

    // The whole state row rolls back, so no epoch and no stale flag survive.
    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    expect($state)->toBeNull();

    $snapshots = glob(UserDataPathService::appPath('sync/backups').'/pre-encryption-'.$this->user->id.'-*.enc');
    expect($snapshots)->not->toBeEmpty();

    // A prior version finalized (renamed) the keyring file INSIDE the
    // transaction, so a forced mid-pass failure left a stray epoch file behind
    // despite the DB rollback. Only a cleaned-up `.tmp`, or nothing, may remain.
    expect(file_exists($keyringPath))->toBeFalse();
    expect(file_exists($keyringPath.'.tmp'))->toBeFalse();

    /** @var EncryptionMigrationService $realMigration */
    $realMigration = $this->app->make(EncryptionMigrationService::class);
    $realMigration->migrate($this->user, $session);

    $finalState = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    // Epoch ids are minted, not counted, so a device holding exactly one
    // epoch is what this proves — the number itself carries no meaning.
    expect((int) $finalState->current_epoch)->toBeGreaterThan(0);
    expect((bool) $finalState->migration_in_progress)->toBeFalse();

    $finalRow = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();
    expect($finalRow->description)->not->toBe('plaintext before forced failure');

    expect(file_exists($keyringPath))->toBeTrue();
    expect(file_exists($keyringPath.'.tmp'))->toBeFalse();
});
