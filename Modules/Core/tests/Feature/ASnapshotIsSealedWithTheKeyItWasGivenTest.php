<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The KEK handed to takeSnapshot() is already a uniformly random data key, so
// the passphrase path's Argon2 stretch buys nothing and costs ~500ms on a
// device that is mid-migration. Read off the header rather than asserted on the
// call, so reverting the call site is what turns this red.

it('seals a pre-migration snapshot at the key cost, not the passphrase cost', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'snapshot-cost',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $path = app(PreMigrationSnapshot::class)
        ->takeSnapshot($user->id, $db->connection(), random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));

    $header = (string) file_get_contents($path, false, null, 0, 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + 12);

    expect(substr($header, 0, 8))->toBe('BTRXENC1');

    $opslimit = unpack('V', substr($header, 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES, 4));
    $memlimit = unpack('P', substr($header, 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + 4, 8));

    expect($opslimit[1])->toBe(1);
    expect($memlimit[1])->toBe(8192);
    expect($opslimit[1])->not->toBe(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE);

    @unlink($path);
});
