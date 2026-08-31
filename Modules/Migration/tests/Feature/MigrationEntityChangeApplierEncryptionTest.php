<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

function mecaUser(): User
{
    return User::query()->create([
        'username' => 'meca-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function mecaAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'meca Checking',
        'slug' => 'meca-checking-'.bin2hex(random_bytes(4)),
        'kind' => 'checking',
        'iban' => 'NL-MECA-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

// counterparty_normalized stays plaintext and is the only merchant column
// FingerprintComposer consumes, so a row whose name/iban/description are
// ciphertext still hashes to what a plaintext re-import would produce.
function mecaSeedTransaction(
    User $user,
    Account $account,
    SensitiveColumnCodec $codec,
    Session $session,
    string $plainName,
    string $plainIban,
    string $plainDescription,
    int $amountMinor,
): int {
    $db = app(DatabaseManager::class);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'ynab4-csv',
        'raw_file_path' => '/tmp/meca-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'meca-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    $normalized = 'meca-const-'.bin2hex(random_bytes(4));

    $composer = app(FingerprintComposer::class);
    $canonical = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 09:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: $amountMinor,
        currency: 'EUR',
        settledAmountMinor: $amountMinor,
        settledCurrency: 'EUR',
        counterpartyName: $plainName,
        counterpartyIban: $plainIban,
        counterpartyNormalized: $normalized,
        normalizationVersion: 3,
        description: $plainDescription,
        categoryId: null,
        sourceFormat: 'ynab4-csv',
        importRunId: $run->id,
        sourceRowIndex: 0,
        sourceRef: 'MECA-'.bin2hex(random_bytes(6)),
    );
    $initialFingerprint = $composer->compose($canonical);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-03-01',
        'booked_at' => '2026-03-01 09:00:00',
        'value_date' => '2026-03-01',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $codec->encryptValue('transactions', 'counterparty_name', $plainName, $user->id, $session),
        'counterparty_iban' => $codec->encryptValue('transactions', 'counterparty_iban', $plainIban, $user->id, $session),
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'description' => $codec->encryptValue('transactions', 'description', $plainDescription, $user->id, $session),
        'source_format' => 'ynab4-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'source_ref' => 'MECA-ROW-'.bin2hex(random_bytes(6)),
        'fingerprint' => $initialFingerprint,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('apply() encrypts an incoming transaction description before the raw update() — encrypted user', function (): void {
    $user = mecaUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = mecaAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $transactionId = mecaSeedTransaction($user, $account, $codec, $session, 'Albert Heijn', 'NL91ABNA0417164300', 'Old description', -2500);

    $sourceExternalId = 'meca-src-'.bin2hex(random_bytes(4));
    app(DatabaseManager::class)->connection()->table('migration_source_map')->insert([
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

    $applied = app(EntityChangeApplier::class)->apply($user, 'ynab4', 'transaction', $sourceExternalId, ['description' => 'New description from re-import']);
    expect($applied)->toBeTrue();

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();

    // Pre-fix: this would be the raw incoming plaintext.
    expect($row->description)->not->toBe('New description from re-import');
    expect($codec->decryptValue('transactions', 'description', $row->description, $user->id, $session)['value'])
        ->toBe('New description from re-import');
})->group('MigrationEntityChangeApplierEncryption');

it('apply() stores plaintext for a non-encrypted user (pass-through parity)', function (): void {
    $user = mecaUser();
    $account = mecaAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);

    $transactionId = mecaSeedTransaction($user, $account, $codec, $session, 'Albert Heijn', 'NL91ABNA0417164300', 'Old description', -2500);

    $sourceExternalId = 'meca-src-'.bin2hex(random_bytes(4));
    app(DatabaseManager::class)->connection()->table('migration_source_map')->insert([
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

    $applied = app(EntityChangeApplier::class)->apply($user, 'ynab4', 'transaction', $sourceExternalId, ['description' => 'New description from re-import']);
    expect($applied)->toBeTrue();

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();
    expect($row->description)->toBe('New description from re-import');
})->group('MigrationEntityChangeApplierEncryption');

it('applyTransactionAmount() recomputes the SAME fingerprint a plaintext re-import would, for a row carrying ciphertext counterparty_name/iban/description — encrypted user', function (): void {
    $user = mecaUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = mecaAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $plainName = 'Albert Heijn';
    $plainIban = 'NL91ABNA0417164300';
    $plainDescription = 'Weekly groceries';
    $newAmountMinor = -5000;

    $transactionId = mecaSeedTransaction($user, $account, $codec, $session, $plainName, $plainIban, $plainDescription, -4500);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();
    $normalized = (string) $row->counterparty_normalized;

    // Ground truth: what a fresh plaintext re-import of this same logical row,
    // with the new amount, would hash to.
    $expectedCanonical = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 09:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: $newAmountMinor,
        currency: 'EUR',
        settledAmountMinor: $newAmountMinor,
        settledCurrency: 'EUR',
        counterpartyName: $plainName,
        counterpartyIban: $plainIban,
        counterpartyNormalized: $normalized,
        normalizationVersion: 3,
        description: $plainDescription,
        categoryId: null,
        sourceFormat: 'ynab4-csv',
        importRunId: (int) $row->import_run_id,
        sourceRowIndex: 0,
        sourceRef: (string) $row->source_ref,
    );
    $expectedFingerprint = app(FingerprintComposer::class)->compose($expectedCanonical);

    $applied = app(EntityChangeApplier::class)->applyTransactionAmount($user, $transactionId, $newAmountMinor);
    expect($applied)->toBeTrue();

    $updatedRow = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();

    expect((int) $updatedRow->amount_minor)->toBe($newAmountMinor);
    expect((string) $updatedRow->fingerprint)->toBe($expectedFingerprint);

    // The whole amount moves, not just the leg the fingerprint is hashed over.
    expect((int) $updatedRow->settled_amount_minor)->toBe($newAmountMinor);
    expect((string) $updatedRow->settled_currency)->toBe('EUR');
    expect($updatedRow->fx_rate_used)->toBeNull();

    // The sealed columns are not in that set and stay sealed and readable.
    expect($updatedRow->counterparty_name)->not->toBe($plainName);
    expect($codec->decryptValue('transactions', 'counterparty_name', $updatedRow->counterparty_name, $user->id, $session)['value'])
        ->toBe($plainName);
})->group('MigrationEntityChangeApplierEncryption');

it('applyTransactionAmount() recomputes the fingerprint unaffected by encryption for a non-encrypted user (pass-through parity)', function (): void {
    $user = mecaUser();
    $account = mecaAccount($user);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);

    $plainName = 'Albert Heijn';
    $plainIban = 'NL91ABNA0417164300';
    $plainDescription = 'Weekly groceries';
    $newAmountMinor = -5000;

    $transactionId = mecaSeedTransaction($user, $account, $codec, $session, $plainName, $plainIban, $plainDescription, -4500);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();
    $normalized = (string) $row->counterparty_normalized;

    $expectedCanonical = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 09:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: $newAmountMinor,
        currency: 'EUR',
        settledAmountMinor: $newAmountMinor,
        settledCurrency: 'EUR',
        counterpartyName: $plainName,
        counterpartyIban: $plainIban,
        counterpartyNormalized: $normalized,
        normalizationVersion: 3,
        description: $plainDescription,
        categoryId: null,
        sourceFormat: 'ynab4-csv',
        importRunId: (int) $row->import_run_id,
        sourceRowIndex: 0,
        sourceRef: (string) $row->source_ref,
    );
    $expectedFingerprint = app(FingerprintComposer::class)->compose($expectedCanonical);

    app(EntityChangeApplier::class)->applyTransactionAmount($user, $transactionId, $newAmountMinor);

    $updatedRow = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $transactionId)->first();
    expect((string) $updatedRow->fingerprint)->toBe($expectedFingerprint);
    expect((int) $updatedRow->amount_minor)->toBe($newAmountMinor);
    expect((int) $updatedRow->settled_amount_minor)->toBe($newAmountMinor);
})->group('MigrationEntityChangeApplierEncryption');
