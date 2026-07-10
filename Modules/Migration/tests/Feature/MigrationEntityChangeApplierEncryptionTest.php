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

/*
 * 14.1-14 Task 2 (Cluster 4 / CRYPT-01):
 *
 *  - T-14.1-14b: EntityChangeApplier::apply() must encrypt
 *    counterparty_name/description in $fields before the raw ->update() —
 *    mirrors TagTransaction's encrypt-before-write.
 *  - T-14.1-14c (the flagged lower-confidence sub-issue): proves the
 *    applyTransactionAmount() fingerprint-recompute path preserves
 *    transactions_fingerprint_uq idempotency under encryption. Investigation
 *    (documented on EntityChangeApplier::applyTransactionAmount()'s own
 *    docblock) found FingerprintComposer::compose() does NOT consume
 *    counterparty_name/counterparty_iban/description bytes at all — only
 *    counterparty_normalized, which is never a SensitiveFieldRegistry column
 *    (always plaintext). This test proves that finding empirically rather
 *    than leaving it as an assumption: the fingerprint recomputed from a row
 *    carrying CIPHERTEXT counterparty_name/counterparty_iban/description is
 *    byte-for-byte identical to the fingerprint a fresh plaintext re-import
 *    of the same logical row would produce.
 */

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

/**
 * Seeds a real `transactions` row with `counterparty_name`/
 * `counterparty_iban`/`description` stored as GENUINE CIPHERTEXT (mirrors
 * what a real encrypted-user import writes at rest). `counterparty_normalized`
 * is stored plaintext (never encrypted, D-02b) and is IDENTICAL to what a
 * fresh plaintext re-import of the same logical row would compute, so the
 * two CanonicalTransaction DTOs built later in the test (one from this raw
 * ciphertext-carrying row, one from known plaintext) hash to the same
 * fingerprint tuple.
 */
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
        fxRateUsed: null,
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

it('T-14.1-14c: applyTransactionAmount() recomputes the SAME fingerprint a plaintext re-import would, for a row carrying ciphertext counterparty_name/iban/description — encrypted user', function (): void {
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

    // Independently compute what a FRESH PLAINTEXT re-import of this exact
    // logical row (same account/dates/currency/normalized-merchant, new
    // amount) would hash to — the ground truth this test proves the
    // encrypted-row recompute matches.
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
        fxRateUsed: null,
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
    // The core idempotency proof: the recomputed fingerprint (produced from
    // a row whose counterparty_name/counterparty_iban/description are
    // CIPHERTEXT at read time) is byte-for-byte identical to the plaintext
    // ground truth above.
    expect((string) $updatedRow->fingerprint)->toBe($expectedFingerprint);

    // The sensitive columns this method never writes stay untouched
    // ciphertext (applyTransactionAmount only writes amount_minor/fingerprint).
    expect($updatedRow->counterparty_name)->not->toBe($plainName);
    expect($codec->decryptValue('transactions', 'counterparty_name', $updatedRow->counterparty_name, $user->id, $session)['value'])
        ->toBe($plainName);
})->group('MigrationEntityChangeApplierEncryption');

it('T-14.1-14c: fingerprint recompute is unaffected by encryption for a non-encrypted user (pass-through parity)', function (): void {
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
        fxRateUsed: null,
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
})->group('MigrationEntityChangeApplierEncryption');
