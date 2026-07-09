<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/*
 * Smoke test for the shared EnablesEncryptionForUser Wave-0 fixture (14.1-01
 * Task 2): proves the trait actually turns encryption on — a value encrypted
 * afterwards is stored as ciphertext (not the plaintext) and decrypts back to
 * the original plaintext. Every downstream 14.1 regression test relies on
 * this trait to avoid the documented pitfall of testing against a plaintext
 * user, where decrypt-of-plaintext is a no-op and a broken path passes
 * silently.
 */

function enablesEncryptionForUserTestUser(): User
{
    return User::query()->create([
        'username' => 'eefu-user-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('eefu-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('enablesEncryptionForUser primes the KEK so a written value is stored as ciphertext and decrypts back to plaintext', function (): void {
    $user = enablesEncryptionForUserTestUser();
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $stored = $codec->encryptValue('transactions', 'note', 'hello', (int) $user->id, $session);

    expect($stored)->not->toBe('hello');

    $decrypted = $codec->decryptValue('transactions', 'note', $stored, (int) $user->id, $session);

    expect($decrypted)->toBe(['value' => 'hello', 'decrypted' => true]);
});
