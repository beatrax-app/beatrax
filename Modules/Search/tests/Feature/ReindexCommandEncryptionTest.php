<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The rebuild read the raw builder, so every encrypted column it copied into
// transaction_search_docs was AEAD base64 — the FTS5 corpus, not a display
// surface, which is why nobody saw it and search simply stopped matching.

function rceUser(string $username): User
{
    return User::query()->create([
        'username' => $username.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @return array{id: int, storedName: string}
 */
function rceEncryptedTransaction(User $user, SensitiveColumnCodec $codec, Session $session, string $name, string $description): array
{
    $txId = test()->searchTestTransaction($user->id, [
        'counterparty_name' => $name,
        'description' => $description,
    ], seedFts: false);

    $storedName = $codec->encryptValue('transactions', 'counterparty_name', $name, $user->id, $session);

    app(DatabaseManager::class)->connection()->table('transactions')
        ->where('id', $txId)
        ->update([
            'counterparty_name' => $storedName,
            'description' => $codec->encryptValue('transactions', 'description', $description, $user->id, $session),
        ]);

    return ['id' => $txId, 'storedName' => $storedName];
}

function rceBody(int $txId): string
{
    $value = app(DatabaseManager::class)->connection()->table('transaction_search_docs')
        ->where('transaction_id', $txId)
        ->value('search_body');

    return is_string($value) ? $value : '';
}

it('indexes the decrypted columns, never the stored ciphertext', function (): void {
    $user = rceUser('rce-unlocked');
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    $tx = rceEncryptedTransaction($user, $codec, $session, 'Albert Heijn', 'Weekly groceries');

    // The fixture is only meaningful if the column really holds ciphertext.
    expect($tx['storedName'])->not->toContain('Albert Heijn');

    $this->artisan('search:reindex')->assertExitCode(0);

    $body = rceBody($tx['id']);
    expect($body)->toContain('Albert Heijn');
    expect($body)->toContain('Weekly groceries');
    expect($body)->not->toContain($tx['storedName']);
})->group('ReindexCommandEncryption');

it('refuses a user whose key material this process does not hold, and leaves their index untouched', function (): void {
    $user = rceUser('rce-locked');
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    $tx = rceEncryptedTransaction($user, $codec, $session, 'Albert Heijn', 'Weekly groceries');

    app(DatabaseManager::class)->connection()->table('transaction_search_docs')->insert([
        'transaction_id' => $tx['id'],
        'user_id' => $user->id,
        'search_body' => 'pre-existing body',
    ]);

    // What a console run always looks like: encryption on, no app-lock key.
    AppLockTestHarness::lock($session);

    $this->artisan('search:reindex')->assertExitCode(1);

    expect(rceBody($tx['id']))->toBe('pre-existing body');
})->group('ReindexCommandEncryption');

it('still rebuilds a plaintext user when another user is refused', function (): void {
    $encrypted = rceUser('rce-mixed-enc');
    $session = $this->enablesEncryptionForUser($encrypted);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    $encryptedTx = rceEncryptedTransaction($encrypted, $codec, $session, 'Albert Heijn', 'Weekly groceries');

    $plain = rceUser('rce-mixed-plain');
    $plainTxId = $this->searchTestTransaction($plain->id, ['counterparty_name' => 'Jumbo'], seedFts: false);

    AppLockTestHarness::lock($session);

    $this->artisan('search:reindex')->assertExitCode(1);

    expect(rceBody($plainTxId))->toContain('Jumbo');
    expect(rceBody($encryptedTx['id']))->toBe('');
})->group('ReindexCommandEncryption');

// A blanked column is ciphertext no epoch in this keyring opens. Writing the
// empty string over it removed the row from the index and still printed "FTS
// index rebuilt", so a search that silently stopped finding it looked healthy.
it('leaves a row it cannot decrypt out of the index and reports the rebuild incomplete', function (): void {
    $user = rceUser('rce-foreign-epoch');
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    $readable = rceEncryptedTransaction($user, $codec, $session, 'Jumbo', 'Weekly groceries');
    $unreadable = rceEncryptedTransaction($user, $codec, $session, 'Albert Heijn', 'Weekly groceries');

    app(DatabaseManager::class)->connection()->table('transactions')
        ->where('id', $unreadable['id'])
        ->update(['description' => base64_encode(random_bytes(48))]);

    $this->artisan('search:reindex')->assertExitCode(1);

    expect(rceBody($readable['id']))->toContain('Jumbo');
    expect(rceBody($unreadable['id']))->toBe('');
})->group('ReindexCommandEncryption');
