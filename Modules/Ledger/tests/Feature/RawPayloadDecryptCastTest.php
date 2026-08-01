<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

/*
 * 14.1-05 — CR-05/D-04/D-05: `transactions.raw_payload` decrypts before
 * `json_decode` on every Eloquent reader via the new `EncryptedJsonCast`,
 * and the cast never re-encrypts what `RecordTransactions::encryptAttrs()`
 * already encrypted (get-only, no double-encrypt).
 */

function rpdUser(): User
{
    return User::query()->create([
        'username' => 'rpd-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function rpdAccount(User $user): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'ASN rpd',
        'slug' => 'rpd-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function rpdImportRun(User $user): ImportRun
{
    return ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rpd.csv',
        'sha256' => hash('sha256', 'rpd-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
}

it('encrypts raw_payload at rest and the Eloquent cast decrypts it back to the original array', function (): void {
    $user = rpdUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = rpdAccount($user);
    $run = rpdImportRun($user);

    $payload = ['events' => [['type' => 'Bankstorting', 'row' => ['Naam' => 'NL91ABNA0417164300']]]];

    $action = $this->app->make(RecordTransactions::class);
    $row = $this->canonical([
        'userId' => $user->id,
        'accountId' => $account->id,
        'importRunId' => $run->id,
        'rawPayload' => $payload,
    ]);
    $action([$row], $user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->first();

    // Ciphertext at rest — a plain json_encode of the payload must not be readable directly.
    expect($stored->raw_payload)->not->toBe(json_encode($payload));

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $decryptedRaw = $codec->decryptValue('transactions', 'raw_payload', $stored->raw_payload, $user->id, $session)['value'];
    expect(json_decode($decryptedRaw, true))->toBe($payload);

    // The Eloquent cast (EncryptedJsonCast) round-trips the SAME value —
    // a double-encrypt would leave leftover ciphertext that json_decode
    // cannot parse, collapsing this to null.
    $tx = Transaction::query()->where('user_id', $user->id)->firstOrFail();
    expect($tx->raw_payload)->toBe($payload);
});

it('stores raw_payload in plaintext for a non-encrypted user and the cast still decodes it (pass-through)', function (): void {
    $user = rpdUser();
    $account = rpdAccount($user);
    $run = rpdImportRun($user);

    $payload = ['events' => [['type' => 'Transfer to bank', 'row' => ['Memo' => 'NL57ASNB0123456789']]]];

    $action = $this->app->make(RecordTransactions::class);
    $row = $this->canonical([
        'userId' => $user->id,
        'accountId' => $account->id,
        'importRunId' => $run->id,
        'rawPayload' => $payload,
    ]);
    $action([$row], $user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->first();
    expect($stored->raw_payload)->toBe(json_encode($payload));

    $tx = Transaction::query()->where('user_id', $user->id)->firstOrFail();
    expect($tx->raw_payload)->toBe($payload);
});

it('returns null from the cast when raw_payload is absent, never crashing', function (): void {
    $user = rpdUser();
    $this->enablesEncryptionForUser($user);
    $account = rpdAccount($user);
    $run = rpdImportRun($user);

    $action = $this->app->make(RecordTransactions::class);
    $row = $this->canonical([
        'userId' => $user->id,
        'accountId' => $account->id,
        'importRunId' => $run->id,
    ]);
    $action([$row], $user);

    $tx = Transaction::query()->where('user_id', $user->id)->firstOrFail();
    expect($tx->raw_payload)->toBeNull();
});
