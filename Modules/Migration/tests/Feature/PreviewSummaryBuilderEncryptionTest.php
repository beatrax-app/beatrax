<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/*
 * 14.1-16 Task 1 (gap-closure / CRYPT-01, T-14.1-16) —
 * PreviewSummaryBuilder::transactionLabel() must decrypt the stored
 * transactions.counterparty_name/description before composing the
 * migration-preview wizard's conflict-row display label. Pre-fix, the raw
 * (ciphertext) column value was interpolated directly into the label, so an
 * encrypted user's /migrations/{id}/preview page rendered a base64-ish blob
 * instead of a merchant name — the last residual instance of the
 * cosmetic ciphertext-display leak class this phase closes (same class as
 * Cluster 1 / plan 10's UncategorizedTriageQuery/ChainLinkQuery fixes).
 *
 * Tests run against a REAL encrypted user via EnablesEncryptionForUser so a
 * decrypt-of-plaintext no-op can never mask a still-broken read.
 */

function psbUser(): User
{
    return User::query()->create([
        'username' => 'psb-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function psbAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'psb Checking',
        'slug' => 'psb-checking-'.bin2hex(random_bytes(4)),
        'kind' => 'checking',
        'iban' => 'NL-PSB-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

/**
 * Seeds a migration run with a single 'conflict' unmapped item pointing at a
 * real `transactions` row whose `counterparty_name`/`description` are stored
 * as GENUINE CIPHERTEXT (via `SensitiveColumnCodec::encryptValue`, mirroring
 * what a real import/op-log write leaves at rest).
 *
 * @return array{runId: int}
 */
function psbSeedConflict(
    User $user,
    Account $account,
    SensitiveColumnCodec $codec,
    Session $session,
    ?string $counterpartyName,
    ?string $description,
    string $fieldName,
): array {
    $db = app(DatabaseManager::class);

    $importRun = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'ynab4-csv',
        'raw_file_path' => '/tmp/psb-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'psb-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    $run = MigrationRun::create([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'psb.zip',
    ]);

    $encryptedCounterpartyName = $counterpartyName === null
        ? null
        : $codec->encryptValue('transactions', 'counterparty_name', $counterpartyName, $user->id, $session);
    $encryptedDescription = $description === null
        ? null
        : $codec->encryptValue('transactions', 'description', $description, $user->id, $session);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-03-01',
        'booked_at' => '2026-03-01 09:00:00',
        'value_date' => '2026-03-01',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_name' => $encryptedCounterpartyName,
        'counterparty_normalized' => 'psb-const-'.bin2hex(random_bytes(4)),
        'normalization_version' => 3,
        'description' => $encryptedDescription,
        'source_format' => 'ynab4-csv',
        'import_run_id' => $importRun->id,
        'source_row_index' => 0,
        'source_ref' => 'PSB-'.bin2hex(random_bytes(6)),
        'fingerprint' => bin2hex(random_bytes(32)),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sourceExternalId = 'psb-row-'.bin2hex(random_bytes(4));

    $db->connection()->table('migration_source_map')->insert([
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

    $db->connection()->table('migration_staging_unmapped_items')->insert([
        'user_id' => $user->id,
        'migration_run_id' => $run->id,
        'item_type' => 'conflict',
        'source_external_id' => $sourceExternalId,
        'entity_type' => 'transaction',
        'field_name' => $fieldName,
        'local_value' => $fieldName === 'amount_minor' ? '-1500' : ($description ?? $counterpartyName ?? ''),
        'source_value' => $fieldName === 'amount_minor' ? '-2000' : 'Updated by store',
        'baseline_value' => null,
        'currency' => $fieldName === 'amount_minor' ? 'EUR' : null,
        'resolution' => null,
        'display_label' => 'Transaction '.$fieldName,
        'reason' => 'legacy fallback reason',
    ]);

    return ['runId' => $run->id];
}

it('decrypts counterparty_name for the migration-preview conflict label — encrypted user', function (): void {
    $user = psbUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = psbAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $fixture = psbSeedConflict(
        $user,
        $account,
        $codec,
        $session,
        counterpartyName: 'Albert Heijn',
        description: null,
        fieldName: 'description',
    );

    $summary = app(PreviewSummaryBuilder::class)->forRun($fixture['runId'], $user);

    $conflictItems = $summary->unmapped['conflict']['items'];
    expect($conflictItems)->toHaveCount(1);
    // Pre-fix this would contain the raw ciphertext blob instead.
    expect($conflictItems[0]['label'])->toContain('Albert Heijn');
})->group('PreviewSummaryBuilderEncryption');

it('decrypts description for the migration-preview conflict label when counterparty_name is null — encrypted user', function (): void {
    $user = psbUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = psbAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $fixture = psbSeedConflict(
        $user,
        $account,
        $codec,
        $session,
        counterpartyName: null,
        description: 'Weekly groceries',
        fieldName: 'description',
    );

    $summary = app(PreviewSummaryBuilder::class)->forRun($fixture['runId'], $user);

    $conflictItems = $summary->unmapped['conflict']['items'];
    expect($conflictItems)->toHaveCount(1);
    expect($conflictItems[0]['label'])->toContain('Weekly groceries');
})->group('PreviewSummaryBuilderEncryption');

it('produces the same label for a non-encrypted user (pass-through parity)', function (): void {
    $user = psbUser();
    $account = psbAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);

    $fixture = psbSeedConflict(
        $user,
        $account,
        $codec,
        $session,
        counterpartyName: 'Jumbo',
        description: null,
        fieldName: 'description',
    );

    $summary = app(PreviewSummaryBuilder::class)->forRun($fixture['runId'], $user);

    $conflictItems = $summary->unmapped['conflict']['items'];
    expect($conflictItems)->toHaveCount(1);
    expect($conflictItems[0]['label'])->toContain('Jumbo');
})->group('PreviewSummaryBuilderEncryption');
