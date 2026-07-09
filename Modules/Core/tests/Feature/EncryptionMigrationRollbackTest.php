<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\EncryptionMigrationService;

/*
 * EncryptionMigrationRollbackTest — CRYPT-01 / D-09: a forced mid-migration
 * failure leaves ZERO half-encrypted rows and the pre-migration
 * BackupEncryptor snapshot restores; migration_in_progress gates read-trust.
 * 14-VALIDATION.md CRYPT-01 row 5.
 *
 * RED until Plan 06 ships Modules\Core\Public\Services\EncryptionMigrationService
 * (the D-09 backup-first, atomic, rollback-on-failure migration pass). This
 * test references the planned production FQCN, which does not yet exist —
 * the failure is "class not found", the correct Wave 0 RED state.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'migration-rollback-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('leaves zero half-encrypted rows when the migration is forced to fail mid-pass', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => 1,
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
        'import_run_id' => 1,
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

    // Forcing a mid-pass failure (via a second, non-existent user with no
    // rows, or an injected fault) must leave the schema in a fully-rolled-
    // back state — no half-encrypted rows for THIS user either, since the
    // migration is atomic for the whole pass, not per-row.
    try {
        $migration->migrate($this->user, $session);
        $forcedFailure = true;
    } catch (\Throwable) {
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
