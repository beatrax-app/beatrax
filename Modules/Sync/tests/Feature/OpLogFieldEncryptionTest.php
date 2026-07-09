<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * OpLogFieldEncryptionTest — CRYPT-01: op-log `value` ciphertext round-trips
 * write->replay; a tampered epoch tag or ciphertext yields
 * OpLogFieldCrypto::decrypt() === false and the entry is routed to
 * op_log_quarantine with reason 'gdk_decrypt_failed' (never throws — "false
 * not garbage"). 14-VALIDATION.md CRYPT-01 row 2.
 *
 * RED until Plan 02 ships Modules\Sync\Internal\Crypto\OpLogFieldCrypto and
 * Plan 03 wires the decrypt-before-decodeValue() hook into OpLogReplayer.
 * These tests reference the planned production FQCN, which does not yet
 * exist — the failure is "class not found", the correct Wave 0 RED state.
 */

function oplogCryptoUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-09 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('round-trips a plaintext value through OpLogFieldCrypto encrypt/decrypt under the same epoch key + associated data', function (): void {
    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $ad = 'transactions:1:note:1';

    $ciphertext = $crypto->encrypt('a private note', $rawGdkKey, $ad);
    expect($ciphertext)->not->toBe('a private note');

    $plaintext = $crypto->decrypt($ciphertext, $rawGdkKey, $ad);
    expect($plaintext)->toBe('a private note');
});

it('returns false (never throws) when the stored ciphertext is tampered', function (): void {
    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $ad = 'transactions:1:note:1';

    $ciphertext = $crypto->encrypt('a private note', $rawGdkKey, $ad);
    $tampered = substr($ciphertext, 0, -4).'xxxx';

    expect($crypto->decrypt($tampered, $rawGdkKey, $ad))->toBeFalse();
});

it('returns false (never throws) for non-base64 or too-short stored input', function (): void {
    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $ad = 'transactions:1:note:1';

    expect($crypto->decrypt('not valid base64!!', $rawGdkKey, $ad))->toBeFalse();
    expect($crypto->decrypt(base64_encode('short'), $rawGdkKey, $ad))->toBeFalse();
});

it('throws InvalidArgumentException for an empty rawGdkKey before any sodium call', function (): void {
    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);

    expect(fn () => $crypto->encrypt('a private note', '', 'transactions:1:note:1'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $crypto->decrypt('anything', '', 'transactions:1:note:1'))
        ->toThrow(InvalidArgumentException::class);
});

it('returns false when the epoch id bound as associated data is relabeled (tamper-resistant epoch tag)', function (): void {
    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    $ciphertext = $crypto->encrypt('a private note', $rawGdkKey, 'transactions:1:note:1');

    // Same ciphertext, but the epoch id embedded in the associated data was
    // relabeled — the AEAD auth tag must fail, proving epoch tampering is
    // detected in defense-in-depth alongside the Ed25519 entry signature.
    expect($crypto->decrypt($ciphertext, $rawGdkKey, 'transactions:1:note:2'))->toBeFalse();
});

it('a corrupted GDK-encrypted op-log entry value is quarantined with reason gdk_decrypt_failed and never throws', function (): void {
    $user = oplogCryptoUser('oplog-crypto-quarantine');
    $userId = (int) $user->id;

    $signer = new DeviceKeySigner;
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    // A deliberately-corrupted ciphertext value simulating a tampered
    // GDK-encrypted op-log payload — Wave 0 pins the contract the replayer
    // must satisfy once Plan 03 wires the decrypt hook: this MUST route to
    // quarantine with reason 'gdk_decrypt_failed', never throw, and never
    // corrupt the target row.
    $stub = new OpLogEntry(
        table: 'categories',
        pk: $categoryId,
        field: 'name',
        value: 'not-a-valid-gdk-ciphertext-payload',
        hlcL: 5000,
        hlcC: 0,
        deviceId: 'oplog-crypto-device',
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );
    $sig = $signer->sign($stub->signingPayload(), $sk);
    $entry = new OpLogEntry(
        table: 'categories',
        pk: $categoryId,
        field: 'name',
        value: 'not-a-valid-gdk-ciphertext-payload',
        hlcL: 5000,
        hlcC: 0,
        deviceId: 'oplog-crypto-device',
        opType: OpType::Set,
        signature: $sig,
        userId: $userId,
    );

    $replayer = new OpLogReplayer($db, ['oplog-crypto-device' => $pkHex]);
    $replayer->replay([$entry], $userId);

    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();

    expect($quarantined)->toBeGreaterThanOrEqual(1);

    // The category name must be untouched — a decrypt failure must never
    // write garbage into the projection.
    expect($db->connection()->table('categories')->where('id', $categoryId)->value('name'))
        ->toBe('Groceries');
});
