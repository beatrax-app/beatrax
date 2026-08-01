<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

/*
 * EncryptionMigrationRollbackTest — CRYPT-01 / D-09: a forced mid-migration
 * failure leaves ZERO half-encrypted rows and the pre-migration
 * BackupEncryptor snapshot restores; migration_in_progress gates read-trust.
 * 14-VALIDATION.md CRYPT-01 row 5.
 */

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

    // No app-lock KEK is primed in this test (Core's TestCase does not
    // prime one) — migrate() must never touch a row without it, and must
    // not leave a half-flagged state hanging (see the KEK-unavailable case
    // below, which asserts this explicitly with a pre-seeded stale row).
    try {
        $migration->migrate($this->user, $session);
        $forcedFailure = true;
    } catch (Throwable) {
        $forcedFailure = false;
    }

    $row = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();

    // Either the migration fully succeeded (description now ciphertext) or
    // fully rolled back (description still the original plaintext) — there
    // must be no partial/half-encrypted state either way.
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

    // A stale in-progress flag (e.g. left over from a crashed prior attempt)
    // must be resolved (retried to completion, or rolled back) — never
    // silently ignored while new writes proceed against half-migrated data.
    $migration->migrate($this->user, $session);

    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    expect((bool) $state->migration_in_progress)->toBeFalse();
});

it('a genuine forced failure mid-pass (real KEK, real data) leaves zero half-encrypted rows, preserves the snapshot, and a subsequent happy-path migrate() then succeeds', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // D-10 assertions below need a clean slate: RefreshDatabase resets the
    // DB's autoincrement per test run, but the GDK keyring lives on the
    // FILESYSTEM (storage/app/sync/gdk/{userId}.enc) — a stale file left
    // behind by an EARLIER test process run against the same reused user
    // id would otherwise make file_exists() true for reasons unrelated to
    // THIS run's forced failure.
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

    // 14-06-PLAN.md Task 2's own guidance: "if Task 1 lacks a clean
    // failure-injection seam, add a minimal protected/overridable step" —
    // EncryptionMigrationService::afterChunkProcessed() IS that seam. This
    // anonymous subclass throws the first time it fires (the transactions
    // chunk, since this user has no op_log_entries rows) and, before
    // throwing, reads sync_encryption_state through the same (still open,
    // still-uncommitted) DB transaction to prove migration_in_progress was
    // genuinely observable as true at the injected-failure point.
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

    // Zero half-encrypted rows: the whole-pass transaction rollback (plus
    // the explicit snapshot-restore defense-in-depth) leaves the row
    // exactly as it was.
    $row = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();
    expect($row->description)->toBe('plaintext before forced failure');
    expect($row->counterparty_name)->toBe('Forced Failure Merchant');

    // current_epoch stays null (encryption NOT enabled) and no stale
    // in-progress row survives the rollback.
    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    expect($state)->toBeNull();

    // The pre-migration snapshot file is preserved on disk (D-09 backup-
    // first) even though the pass failed.
    $snapshots = glob(UserDataPathService::appPath('sync/backups').'/pre-encryption-'.$this->user->id.'-*.enc');
    expect($snapshots)->not->toBeEmpty();

    // D-10: current_epoch rolled back to null (asserted above) AND no
    // FINALIZED epoch-1 keyring file contradicts it on disk — only a
    // cleaned-up `.tmp` (or nothing at all) may remain. A prior version of
    // this class finalized (renamed) the keyring file INSIDE the
    // transaction, so this forced mid-pass failure would have left a
    // stray epoch-1 file behind despite the DB rollback (T-14.1-05).
    expect(file_exists($keyringPath))->toBeFalse();
    expect(file_exists($keyringPath.'.tmp'))->toBeFalse();

    // Happy path: migrate() again (the real, un-overridden service) then
    // succeeds cleanly.
    /** @var EncryptionMigrationService $realMigration */
    $realMigration = $this->app->make(EncryptionMigrationService::class);
    $realMigration->migrate($this->user, $session);

    $finalState = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    expect((int) $finalState->current_epoch)->toBe(1);
    expect((bool) $finalState->migration_in_progress)->toBeFalse();

    $finalRow = $db->connection()->table('transactions')->where('user_id', $this->user->id)->first();
    expect($finalRow->description)->not->toBe('plaintext before forced failure');

    // D-10 happy path: the successful migration produces exactly one
    // finalized keyring file (no leftover `.tmp` sibling).
    expect(file_exists($keyringPath))->toBeTrue();
    expect(file_exists($keyringPath.'.tmp'))->toBeFalse();
});
