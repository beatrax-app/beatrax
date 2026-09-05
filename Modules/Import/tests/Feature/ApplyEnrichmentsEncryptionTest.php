<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

function aeeUser(): User
{
    return User::query()->create([
        'username' => 'aee-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function aeeAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal aee',
        'slug' => 'aee-paypal-'.bin2hex(random_bytes(4)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL-AEE-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function aeeExistingTransaction(User $user, Account $account, ?string $counterpartyName = 'Stored Merchant'): Transaction
{
    static $idx = 0;
    $idx++;

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/aee-'.$idx.'.csv',
        'sha256' => hash('sha256', 'aee-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:00',
        'value_date' => '2026-04-01',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => 'aee merchant',
        'normalization_version' => 1,
        'description' => null,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'source_ref' => null,
        'fingerprint' => str_pad('aee-'.$idx, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
    ]);
}

function aeeSetPolicy(User $user, string $policy): void
{
    app(DatabaseManager::class)->connection()
        ->table('users')
        ->where('id', $user->id)
        ->update(['receipt_conflict_resolution' => $policy]);
}

it('encrypts the incoming counterparty_name/description before the prefer_receipt UPDATE, for an encrypted user', function (): void {
    $user = aeeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = aeeAccount($user);
    $tx = aeeExistingTransaction($user, $account);
    aeeSetPolicy($user, 'prefer_receipt');

    $applier = app(AppliesEnrichments::class);
    $count = $applier([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'PAYID-CANONICAL',
            importRunId: 1,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'Stored Merchant', 'incoming' => 'Albert Heijn'],
                'description' => ['stored' => null, 'incoming' => 'Weekly groceries'],
            ],
        ),
    ], $user);

    expect($count)->toBe(1);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $tx->id)->first();

    // Tripwire: the regression wrote the incoming plaintext straight to the column.
    expect($row->counterparty_name)->not->toBe('Albert Heijn');
    expect($row->description)->not->toBe('Weekly groceries');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transactions', 'counterparty_name', $row->counterparty_name, $user->id, $session)['value'])
        ->toBe('Albert Heijn');
    expect($codec->decryptValue('transactions', 'description', $row->description, $user->id, $session)['value'])
        ->toBe('Weekly groceries');
})->group('ReceiptConflictEncryption');

it('leaves currency/amount_minor conflict values unencrypted (never sensitive)', function (): void {
    $user = aeeUser();
    $this->enablesEncryptionForUser($user);
    $account = aeeAccount($user);
    $tx = aeeExistingTransaction($user, $account);
    aeeSetPolicy($user, 'prefer_receipt');

    $applier = app(AppliesEnrichments::class);
    $applier([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'PAYID-CANONICAL-2',
            importRunId: 1,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'currency' => ['stored' => 'EUR', 'incoming' => 'USD'],
            ],
        ),
    ], $user);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $tx->id)->first();
    expect($row->currency)->toBe('USD');
})->group('ReceiptConflictEncryption');

it('stores tax_transaction_tags-analogue plaintext for a non-encrypted user (pass-through parity)', function (): void {
    $user = aeeUser();
    $account = aeeAccount($user);
    $tx = aeeExistingTransaction($user, $account);
    aeeSetPolicy($user, 'prefer_receipt');

    $applier = app(AppliesEnrichments::class);
    $applier([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'PAYID-CANONICAL-3',
            importRunId: 1,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'Stored Merchant', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $user);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $tx->id)->first();
    expect($row->counterparty_name)->toBe('Albert Heijn');
})->group('ReceiptConflictEncryption');

it('holdConflicts persists + dispatches PLAINTEXT stored/incoming values for an encrypted user (no double-encrypt, no ciphertext leak)', function (): void {
    $user = aeeUser();
    $this->enablesEncryptionForUser($user);
    $account = aeeAccount($user);
    $tx = aeeExistingTransaction($user, $account);
    // No aeeSetPolicy call: the 'unset' DB default is what holds the conflict.

    Event::fake([ReceiptConflictDetected::class]);

    $applier = app(AppliesEnrichments::class);
    $applier([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'PAYID-CANONICAL-4',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                // 'stored' arrives already decrypted from FingerprintStage::detectConflicts.
                'counterparty_name' => ['stored' => 'Stored Merchant', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $user);

    $pending = app(DatabaseManager::class)->connection()
        ->table('pending_enrichment_conflicts')
        ->where('transaction_id', $tx->id)
        ->first();

    expect($pending)->not->toBeNull();
    expect(json_decode((string) $pending->stored_value, true))->toBe('Stored Merchant');
    expect(json_decode((string) $pending->incoming_value, true))->toBe('Albert Heijn');

    Event::assertDispatched(ReceiptConflictDetected::class, function (ReceiptConflictDetected $event): bool {
        return $event->storedValue === 'Stored Merchant' && $event->incomingValue === 'Albert Heijn';
    });
})->group('ReceiptConflictEncryption');
