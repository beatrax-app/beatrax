<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Without decrypting the stored value first, detectConflicts() compares
// ciphertext against incoming plaintext — never equal — so every
// receipt-vs-statement re-import registers a false conflict on every row. These
// run against a real encrypted user so a decrypt no-op cannot mask that.

function fseUser(): User
{
    return User::query()->create([
        'username' => 'fse-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function fseAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal fse',
        'slug' => 'fse-paypal-'.bin2hex(random_bytes(4)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL-FSE-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function fseImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/fse-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'fse-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);
}

// The stored side is genuine ciphertext, as RecordTransactions writes at import
// time. counterpartyNormalized is held identical on both sides because it is in
// the fingerprint tuple and counterparty_name is not, so the match fires
// whatever the display names say.
function fseSeed(
    User $user,
    Account $account,
    ImportRun $run,
    SensitiveColumnCodec $codec,
    Session $session,
    string $storedName,
    ?string $storedDescription,
    string $incomingName,
    ?string $incomingDescription,
): CanonicalTransaction {
    $db = app(DatabaseManager::class);
    $composer = app(FingerprintComposer::class);
    $normalized = 'fse-const-merchant';

    $tx = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 09:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: -2500,
        currency: 'EUR',
        settledAmountMinor: -2500,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: $incomingName,
        counterpartyIban: null,
        counterpartyNormalized: $normalized,
        normalizationVersion: 3,
        description: $incomingDescription,
        categoryId: null,
        sourceFormat: SourceFormat::Eml->value,
        importRunId: $run->id,
        sourceRowIndex: 0,
        sourceRef: 'PAYID-'.bin2hex(random_bytes(6)),
    );

    $fingerprint = $composer->compose($tx);

    $encryptedName = $codec->encryptValue('transactions', 'counterparty_name', $storedName, $user->id, $session);
    $encryptedDescription = $storedDescription === null
        ? null
        : $codec->encryptValue('transactions', 'description', $storedDescription, $user->id, $session);

    $db->connection()->table('transactions')->insert([
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
        'counterparty_name' => $encryptedName,
        'counterparty_iban' => null,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'description' => $encryptedDescription,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'source_ref' => 'O-'.bin2hex(random_bytes(6)),
        'fingerprint' => $fingerprint,
        'fingerprint_version' => $composer->version(),
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tx;
}

it('registers zero conflicts when the decrypted stored value logically equals the incoming value (encrypted user)', function (): void {
    $user = fseUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = fseAccount($user);
    $run = fseImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $tx = fseSeed(
        $user,
        $account,
        $run,
        $codec,
        $session,
        storedName: 'Albert Heijn',
        storedDescription: 'Weekly groceries',
        incomingName: 'Albert Heijn',
        incomingDescription: 'Weekly groceries',
    );

    $stage = app(FingerprintStage::class);
    $disposition = $stage->classify($tx, $user);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect($disposition->conflictingFields)->toBe([]);
})->group('FingerprintStageEncryption');

it('registers a conflict with the DECRYPTED plaintext stored value when the values genuinely differ (encrypted user)', function (): void {
    $user = fseUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = fseAccount($user);
    $run = fseImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $tx = fseSeed(
        $user,
        $account,
        $run,
        $codec,
        $session,
        storedName: 'Albert Heijn',
        storedDescription: 'Weekly groceries',
        incomingName: 'Albert Heijn Amsterdam Centrum',
        incomingDescription: 'Weekly groceries plus wine',
    );

    $stage = app(FingerprintStage::class);
    $disposition = $stage->classify($tx, $user);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    $conflicts = $disposition->conflictingFields;

    expect($conflicts)->toHaveKey('counterparty_name');
    expect($conflicts['counterparty_name']['stored'])->toBe('Albert Heijn');
    expect($conflicts['counterparty_name']['incoming'])->toBe('Albert Heijn Amsterdam Centrum');

    expect($conflicts)->toHaveKey('description');
    expect($conflicts['description']['stored'])->toBe('Weekly groceries');
    expect($conflicts['description']['incoming'])->toBe('Weekly groceries plus wine');
})->group('FingerprintStageEncryption');

it('produces the same conflict-detection outcome for a non-encrypted user (pass-through parity)', function (): void {
    $user = fseUser();
    $account = fseAccount($user);
    $run = fseImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);

    $tx = fseSeed(
        $user,
        $account,
        $run,
        $codec,
        $session,
        storedName: 'Albert Heijn',
        storedDescription: 'Weekly groceries',
        incomingName: 'Albert Heijn',
        incomingDescription: 'Weekly groceries',
    );

    $stage = app(FingerprintStage::class);
    $disposition = $stage->classify($tx, $user);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect($disposition->conflictingFields)->toBe([]);
})->group('FingerprintStageEncryption');
