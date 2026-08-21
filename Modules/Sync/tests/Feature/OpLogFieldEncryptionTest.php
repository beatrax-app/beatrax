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

// A failed decrypt returns false and quarantines rather than throwing or
// writing what it got: garbage in a projection column is indistinguishable from
// a real value, and the op log is the source of truth that would replay it.

function oplogCryptoUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

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

// Reads the durable row back, exactly as a peer receiving it over the wire —
// or a rebuild — would reconstruct it.
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

    // Same ciphertext with the epoch id in the associated data relabelled: the
    // auth tag must fail, independently of the entry signature.
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

    $writer->writeSet('transactions', $txnId, 'note', 'a private note');

    $storedRow = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->first();

    expect($storedRow)->not->toBeNull();
    expect($storedRow->value)->not->toBe(json_encode('a private note'));
    // Epoch ids are minted rather than counted, so what matters is that the
    // device holds exactly one, not which number it reads.
    expect((int) $storedRow->gdk_epoch)->toBeGreaterThan(0);

    $entry = oplogCryptoReadEntry($db, $userId, 'transactions', 'note', $txnId);

    $replayer = new OpLogReplayer($db, ['oplog-crypto-device-a' => $writer->publicKeyHex()]);
    $replayer->replay([$entry], $userId);

    // Still ciphertext after replay: the decrypted value is transient and is
    // never written back over the durable entry.
    $afterReplayValue = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->value('value');
    expect($afterReplayValue)->toBe($storedRow->value);

    // The projection column holds codec-format ciphertext, and the read path
    // round-trips it back to the edited plaintext.
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

    // The corrupted payload is re-signed with the same device key, so the
    // signature gate cannot be what catches it. A relabelled epoch or corrupted
    // ciphertext has to fail closed on its own.
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

    // A decrypt failure must never write garbage into the projection column.
    expect($db->connection()->table('transactions')->where('id', $txnId)->value('note'))
        ->toBeNull();
});
