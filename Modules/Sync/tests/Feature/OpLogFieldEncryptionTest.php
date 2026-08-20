<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

/*
 * OpLogFieldEncryptionTest — CRYPT-01: op-log `value` ciphertext round-trips
 * write->replay; a tampered epoch tag or ciphertext yields
 * OpLogFieldCrypto::decrypt() === false and the entry is routed to
 * op_log_quarantine with reason 'gdk_decrypt_failed' (never throws — "false
 * not garbage"). 14-VALIDATION.md CRYPT-01 row 2.
 *
 * The crypto-unit cases above were completed in Plan 02. The integration
 * cases below (write->replay round-trip + tamper->quarantine) exercise the
 * REAL OpLogWriter encrypt-on-write hook and OpLogReplayer decrypt-before-
 * strategy hook wired in Plan 03 end-to-end.
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

/**
 * Seed a minimal transactions row (non-sensitive columns only) to attach a
 * sensitive-field op-log SET to.
 */
function oplogCryptoTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN crypto test',
        'slug' => 'crypto-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/crypto-test.csv',
        'sha256' => hash('sha256', 'crypto-run-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'crypto-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'crypto merchant',
        'counterparty_name' => 'Crypto Merchant',
        'normalization_version' => 3,
        'description' => 'oplog field encryption test',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

/**
 * Reconstruct the OpLogEntry the writer just persisted (reads the durable
 * row back, exactly as a peer receiving it over the wire — or a rebuild —
 * would reconstruct it).
 */
function oplogCryptoReadEntry(DatabaseManager $db, int $userId, string $table, string $field, int|string $pk): OpLogEntry
{
    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->where('field', $field)
        ->where('pk', (string) $pk)
        ->firstOrFail();

    return new OpLogEntry(
        table: $table,
        pk: $pk,
        field: $field,
        value: is_string($row->value) ? $row->value : null,
        hlcL: (int) $row->hlc_l,
        hlcC: (int) $row->hlc_c,
        deviceId: (string) $row->device_id,
        opType: OpType::from((string) $row->op_type),
        signature: (string) $row->signature,
        userId: $userId,
        gdkEpoch: is_numeric($row->gdk_epoch) ? (int) $row->gdk_epoch : null,
    );
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

it('a full write->replay round-trip encrypts the op-log entry and lands the decrypted plaintext in the projection column, still ciphertext at rest', function (): void {
    $user = oplogCryptoUser('oplog-crypto-roundtrip');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    // GDK encryption enabled for this user (Sync module TestCase primes an
    // unlocked dummy app-lock KEK for $session — 14-02-SUMMARY).
    $keyringService->generateAndPersist($userId, $session);

    $txnId = oplogCryptoTransaction($db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    /** @var OpLogWriter $writer */
    $writer = $this->app->make(OpLogWriter::class, [
        'deviceId' => 'oplog-crypto-device-a',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    // Real encrypt-on-write hook (Task 1): `note` is D-02b-sensitive.
    $writer->writeSet('transactions', $txnId, 'note', 'a private note');

    $storedRow = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->first();

    expect($storedRow)->not->toBeNull();
    expect($storedRow->value)->not->toBe(json_encode('a private note'));
    // Epoch ids are minted, not counted, so a device holding exactly one
    // epoch is what this proves — the number itself carries no meaning.
    expect((int) $storedRow->gdk_epoch)->toBeGreaterThan(0);

    // Simulate a peer receiving this entry (or a rebuild reading it back) —
    // reconstruct the OpLogEntry from the durable row and replay it.
    $entry = oplogCryptoReadEntry($db, $userId, 'transactions', 'note', $txnId);

    $replayer = new OpLogReplayer($db, ['oplog-crypto-device-a' => $writer->publicKeyHex()]);
    $replayer->replay([$entry], $userId);

    // Durable op-log entry is STILL ciphertext after replay — persistVerifiedEntry
    // never re-persists the transient decrypted plaintext.
    $afterReplayValue = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->value('value');
    expect($afterReplayValue)->toBe($storedRow->value);

    // Projection column (transactions.note) is codec-format ciphertext, NOT
    // plaintext — but SensitiveColumnCodec::decryptRow round-trips it back to
    // the edited plaintext (rotation-safe read path, matching Plan 04's read
    // sites).
    $projectedNote = $db->connection()->table('transactions')->where('id', $txnId)->value('note');
    expect($projectedNote)->not->toBeNull();
    expect($projectedNote)->not->toBe('a private note');

    $decrypted = $codec->decryptRow('transactions', ['note' => $projectedNote], $userId, $session);
    expect($decrypted['note'])->toBe('a private note');
});

it('a byte-flipped op-log entry value is quarantined with reason gdk_decrypt_failed and never throws, leaving the projection row untouched', function (): void {
    $user = oplogCryptoUser('oplog-crypto-tamper');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);

    $keyringService->generateAndPersist($userId, $session);

    $txnId = oplogCryptoTransaction($db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);
    /** @var OpLogWriter $writer */
    $writer = $this->app->make(OpLogWriter::class, [
        'deviceId' => 'oplog-crypto-device-b',
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);

    $writer->writeSet('transactions', $txnId, 'note', 'another private note');

    $entry = oplogCryptoReadEntry($db, $userId, 'transactions', 'note', $txnId);

    // Byte-flip the stored ciphertext — a REAL Ed25519-covered $value change
    // would already be caught by the (separate, defense-in-depth) signature
    // gate, so this re-signs the corrupted payload with the SAME device key,
    // isolating the AEAD/decrypt failure this hook must independently catch
    // (T-14-03: a relabeled epoch or corrupted ciphertext must fail closed
    // even when the entry is otherwise validly signed).
    $tamperedValue = substr((string) $entry->value, 0, -4).'XXXX';
    $tamperedStub = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $tamperedValue,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: '',
        userId: $entry->userId,
        gdkEpoch: $entry->gdkEpoch,
    );
    $tamperedSignature = (new DeviceKeySigner)->sign($tamperedStub->signingPayload(), $sk);
    $tampered = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $tamperedValue,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: $tamperedSignature,
        userId: $entry->userId,
        gdkEpoch: $entry->gdkEpoch,
    );

    $replayer = new OpLogReplayer($db, ['oplog-crypto-device-b' => $writer->publicKeyHex()]);
    $replayer->replay([$tampered], $userId);

    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();

    expect($quarantined)->toBeGreaterThanOrEqual(1);

    // The projection row must be untouched — a decrypt failure must never
    // write garbage into transactions.note (T-05-03 "false not garbage").
    expect($db->connection()->table('transactions')->where('id', $txnId)->value('note'))
        ->toBeNull();
});
