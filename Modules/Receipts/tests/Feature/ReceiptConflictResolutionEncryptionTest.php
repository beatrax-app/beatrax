<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The manual resolve path writes the incoming value straight into transactions,
// so it has to encrypt first for an encrypted user. The pending conflict rows
// themselves stay plaintext, which is what lets the query surface them to the
// UI unchanged.

function rcreUser(): User
{
    return User::query()->create([
        'username' => 'rcre-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function rcreAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal rcre',
        'slug' => 'rcre-paypal-'.bin2hex(random_bytes(4)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL-RCRE-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

/**
 * @return array{tx: Transaction, conflictId: int}
 */
function rcreSeed(User $user, Account $account, string $stored, string $incoming): array
{
    static $idx = 0;
    $idx++;

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/rcre-'.$idx.'.csv',
        'sha256' => hash('sha256', 'rcre-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    // counterparty_name is seeded plaintext on purpose: proving the resolved
    // value came back as ciphertext only means something if it did not start
    // out that way.
    $tx = Transaction::create([
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
        'counterparty_name' => $stored,
        'counterparty_normalized' => 'rcre merchant',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'fingerprint' => str_pad('rcre-'.$idx, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
    ]);

    $conflictId = (int) app(DatabaseManager::class)->connection()->table('pending_enrichment_conflicts')->insertGetId([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
        'field_name' => 'counterparty_name',
        'stored_value' => json_encode($stored, JSON_THROW_ON_ERROR),
        'incoming_value' => json_encode($incoming, JSON_THROW_ON_ERROR),
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => $run->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['tx' => $tx, 'conflictId' => $conflictId];
}

it('encrypts the incoming value before the transactions UPDATE for an encrypted user (manual resolve, prefer_receipt)', function (): void {
    $user = rcreUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = rcreAccount($user);

    $seeded = rcreSeed($user, $account, stored: 'NLPAYPAL ALBERT HEIJN', incoming: 'Albert Heijn');

    $resolve = app(ApplyReceiptConflictResolution::class);
    $count = $resolve($user, 'prefer_receipt');

    expect($count)->toBe(1);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['tx']->id)->first();

    // The regression this catches is the incoming plaintext landing raw.
    expect($row->counterparty_name)->not->toBe('Albert Heijn');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transactions', 'counterparty_name', $row->counterparty_name, $user->id, $session)['value'])
        ->toBe('Albert Heijn');
})->group('ReceiptConflictEncryption');

it('keeps the stored value untouched for prefer_first_write under an encrypted user (no spurious encrypt)', function (): void {
    $user = rcreUser();
    $this->enablesEncryptionForUser($user);
    $account = rcreAccount($user);

    $seeded = rcreSeed($user, $account, stored: 'NLPAYPAL ALBERT HEIJN', incoming: 'Albert Heijn');

    $resolve = app(ApplyReceiptConflictResolution::class);
    $resolve($user, 'prefer_first_write');

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');
})->group('ReceiptConflictEncryption');

it('stores plaintext for a non-encrypted user (pass-through parity, manual resolve)', function (): void {
    $user = rcreUser();
    $account = rcreAccount($user);

    $seeded = rcreSeed($user, $account, stored: 'NLPAYPAL ALBERT HEIJN', incoming: 'Albert Heijn');

    $resolve = app(ApplyReceiptConflictResolution::class);
    $resolve($user, 'prefer_receipt');

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('Albert Heijn');
})->group('ReceiptConflictEncryption');

it('ReceiptConflictQuery displays the plaintext stored/incoming values (no ciphertext leak to the toast/UI)', function (): void {
    $user = rcreUser();
    $this->enablesEncryptionForUser($user);
    $account = rcreAccount($user);

    rcreSeed($user, $account, stored: 'NLPAYPAL ALBERT HEIJN', incoming: 'Albert Heijn');

    $query = app(ReceiptConflictQuery::class);
    $result = $query->latestForUser($user);

    expect($result)->not->toBeNull();
    expect($result['storedValue'])->toBe('NLPAYPAL ALBERT HEIJN');
    expect($result['incomingValue'])->toBe('Albert Heijn');
})->group('ReceiptConflictEncryption');
