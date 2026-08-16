<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

/*
 * The backfill reads rows straight off the table, and a sensitive column is
 * ciphertext AT REST. OpLogWriter then encrypts whatever it is handed — under
 * a DIFFERENT associated data (`table:pk:field:epoch`, not `table:field:epoch`)
 * — so the stored column went into the log wrapped twice.
 *
 * The peer unwrapped the outer layer, got the inner base64, and projected THAT
 * as the value. Its own read-side decrypt then succeeded, so the ciphertext
 * guard never fired and every synced counterparty and description rendered on
 * the phone as a string of characters.
 */

function backfillCryptoUser(): User
{
    return User::query()->create([
        'username' => 'backfill-crypto-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function backfillCryptoWriter(int $userId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'backfill-crypto-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

it('captures the plaintext of an encrypted column, not a second layer of ciphertext', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = backfillCryptoUser();
    $keyring->generateAndPersist($user->id, $session);

    // Written exactly as the real create path writes it: encrypted at rest
    // under the COLUMN associated data before it touches disk.
    $attrs = $codec->encryptAttrs('counterparties', [
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'albert-heijn',
        'display_name' => 'ALBERT HEIJN',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ], $user->id, $session);

    expect($attrs['display_name'])->not->toBe('ALBERT HEIJN', 'fixture must be encrypted at rest');

    $cpId = (int) $db->connection()->table('counterparties')->insertGetId($attrs);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = $this->app->make(OpLogBackfiller::class);
    $backfiller->backfill($user->id, backfillCryptoWriter($user->id));

    $entry = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->where('field', 'display_name')
        ->where('pk', (string) $cpId)
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->gdk_epoch)->toBe(1);

    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);
    $epochKey = sodium_hex2bin($keyring->loadKeyring($user->id, $session)->keyFor(1) ?? '');

    $once = $crypto->decrypt(
        (string) $entry->value,
        $epochKey,
        "counterparties:{$cpId}:display_name:1",
    );

    // ONE unwrap must land on the name. Before the fix this yielded the
    // base64 of the column ciphertext, which is exactly what reached the
    // phone's screen.
    expect($once)->toBe(json_encode('ALBERT HEIJN'));
});

it('refuses to capture a sensitive column it cannot read rather than shipping a blank', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = backfillCryptoUser();
    $keyring->generateAndPersist($user->id, $session);

    // Ciphertext from a DIFFERENT user's keyring: structurally valid, but no
    // epoch in this user's ring opens it. The codec blanks such a value on
    // read, and shipping that blank into the log would erase the name on
    // every peer — permanently, since the log is the source of truth.
    $stranger = backfillCryptoUser();
    $keyring->generateAndPersist($stranger->id, $session);
    $foreign = $codec->encryptValue('counterparties', 'display_name', 'ALBERT HEIJN', $stranger->id, $session);

    $db->connection()->table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'unreadable',
        'display_name' => $foreign,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = $this->app->make(OpLogBackfiller::class);

    expect(fn (): int => $backfiller->backfill($user->id, backfillCryptoWriter($user->id)))
        ->toThrow(RuntimeException::class);
});
