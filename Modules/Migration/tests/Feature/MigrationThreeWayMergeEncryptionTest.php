<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Models\MigrationRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

function mtwmUser(): User
{
    return User::query()->create([
        'username' => 'mtwm-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function mtwmAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'mtwm Checking',
        'slug' => 'mtwm-checking-'.bin2hex(random_bytes(4)),
        'kind' => 'checking',
        'iban' => 'NL-MTWM-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

// The stored description is genuine ciphertext while the baseline snapshot is
// plaintext — the mismatch the resolver has to bridge.
function mtwmSeed(
    User $user,
    Account $account,
    SensitiveColumnCodec $codec,
    Session $session,
    string $storedDescription,
    string $baselineDescription,
    string $sourceNewDescription,
): array {
    $db = app(DatabaseManager::class);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'ynab4-csv',
        'raw_file_path' => '/tmp/mtwm-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'mtwm-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    $encryptedDescription = $codec->encryptValue('transactions', 'description', $storedDescription, $user->id, $session);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-03-01',
        'booked_at' => '2026-03-01 09:00:00',
        'value_date' => '2026-03-01',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'mtwm-const-'.bin2hex(random_bytes(4)),
        'normalization_version' => 3,
        'description' => $encryptedDescription,
        'source_format' => 'ynab4-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'source_ref' => 'MTWM-'.bin2hex(random_bytes(6)),
        'fingerprint' => bin2hex(random_bytes(32)),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sourceExternalId = 'mtwm-row-'.bin2hex(random_bytes(4));

    $mapId = $db->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'source_entity_type' => 'transaction',
        'source_external_id' => $sourceExternalId,
        'beatrax_entity_type' => 'transaction',
        'beatrax_id' => $transactionId,
        'natural_key' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('migration_import_baseline')->insert([
        'user_id' => $user->id,
        'migration_source_map_id' => $mapId,
        'field_name' => 'description',
        'baseline_value' => $baselineDescription,
        'imported_at' => now(),
    ]);

    $newRun = MigrationRun::create([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'mtwm-new.zip',
    ]);

    $db->connection()->table('migration_staging_transactions')->insert([
        'user_id' => $user->id,
        'migration_run_id' => $newRun->id,
        'source_external_id' => $sourceExternalId,
        'account_source_external_id' => 'mtwm-account-ext',
        'posted_at' => '2026-03-01 00:00:00',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'description' => $sourceNewDescription,
        'cleared_status' => 'cleared',
        'is_split_parent' => false,
        'parent_source_external_id' => null,
    ]);

    return [
        'newRunId' => $newRun->id,
        'transactionId' => $transactionId,
    ];
}

it('applies the source description cleanly (no spurious conflict) when the decrypted stored value equals the baseline — encrypted user', function (): void {
    $user = mtwmUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = mtwmAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    // Stored decrypts to the baseline; only the source changed. Pre-fix, raw
    // ciphertext !== plaintext baseline made this a spurious conflict.
    $fixture = mtwmSeed(
        $user,
        $account,
        $codec,
        $session,
        storedDescription: 'Weekly groceries',
        baselineDescription: 'Weekly groceries',
        sourceNewDescription: 'Weekly groceries (updated by store)',
    );

    $decision = app(ThreeWayMergeResolver::class)->resolve($fixture['newRunId'], $user, 'ynab4');

    expect($decision->conflicts)->toBe([]);

    $transactionApplies = array_values(array_filter(
        $decision->applies,
        static fn (array $apply): bool => $apply['entityType'] === 'transaction' && ($apply['fields']['description'] ?? null) !== null,
    ));
    expect($transactionApplies)->toHaveCount(1);
    expect($transactionApplies[0]['fields']['description'])->toBe('Weekly groceries (updated by store)');
})->group('MigrationThreeWayMergeEncryption');

it('registers a conflict carrying the DECRYPTED plaintext local value when the stored description genuinely diverged from baseline — encrypted user', function (): void {
    $user = mtwmUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = mtwmAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $fixture = mtwmSeed(
        $user,
        $account,
        $codec,
        $session,
        storedDescription: 'Weekly groceries plus wine',
        baselineDescription: 'Weekly groceries',
        sourceNewDescription: 'Weekly groceries (updated by store)',
    );

    $decision = app(ThreeWayMergeResolver::class)->resolve($fixture['newRunId'], $user, 'ynab4');

    $conflicts = array_values(array_filter(
        $decision->conflicts,
        static fn ($conflict): bool => $conflict->entityType === 'transaction' && $conflict->fieldName === 'description',
    ));
    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]->localValue)->toBe('Weekly groceries plus wine');
    expect($conflicts[0]->sourceValue)->toBe('Weekly groceries (updated by store)');
    expect($conflicts[0]->baselineValue)->toBe('Weekly groceries');
})->group('MigrationThreeWayMergeEncryption');

it('skips (no apply, no conflict) when the source value is unchanged from the baseline — encrypted user', function (): void {
    $user = mtwmUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = mtwmAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $fixture = mtwmSeed(
        $user,
        $account,
        $codec,
        $session,
        storedDescription: 'Weekly groceries',
        baselineDescription: 'Weekly groceries',
        sourceNewDescription: 'Weekly groceries',
    );

    $decision = app(ThreeWayMergeResolver::class)->resolve($fixture['newRunId'], $user, 'ynab4');

    expect($decision->conflicts)->toBe([]);
    $transactionApplies = array_values(array_filter(
        $decision->applies,
        static fn (array $apply): bool => $apply['entityType'] === 'transaction',
    ));
    expect($transactionApplies)->toBe([]);
})->group('MigrationThreeWayMergeEncryption');

it('produces the same apply/conflict outcome for a non-encrypted user (pass-through parity)', function (): void {
    $user = mtwmUser();
    $account = mtwmAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);

    $fixture = mtwmSeed(
        $user,
        $account,
        $codec,
        $session,
        storedDescription: 'Weekly groceries',
        baselineDescription: 'Weekly groceries',
        sourceNewDescription: 'Weekly groceries (updated by store)',
    );

    $decision = app(ThreeWayMergeResolver::class)->resolve($fixture['newRunId'], $user, 'ynab4');

    expect($decision->conflicts)->toBe([]);
    $transactionApplies = array_values(array_filter(
        $decision->applies,
        static fn (array $apply): bool => $apply['entityType'] === 'transaction' && ($apply['fields']['description'] ?? null) !== null,
    ));
    expect($transactionApplies)->toHaveCount(1);
})->group('MigrationThreeWayMergeEncryption');
