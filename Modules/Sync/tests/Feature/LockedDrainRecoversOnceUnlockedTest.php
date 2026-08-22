<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// `sync:serve` is a console daemon with no session, so on a desktop EVERY peer
// drain runs with no app-lock key. The entry is persisted and the projection is
// refused, which is correct — and until something replays it once a key is
// back, the peer's edit is only ever visible in an audit table.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-entries-a-locked-desktop-quarantines
 */
const DRAIN_PEER_DEVICE = 'drain-peer-device';

const DRAIN_PEER_NOTE = 'IBAN of the plumber, from the phone';

function drainUser(): User
{
    return User::query()->create([
        'username' => 'drain-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function drainTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN drain test',
        'slug' => 'drain-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drain-test.csv',
        'sha256' => hash('sha256', 'drain-run-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'drain-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'drain merchant',
        'counterparty_name' => 'Drain Merchant',
        'normalization_version' => 3,
        'description' => 'drain test row',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

// A peer only reaches the drain at all by being paired and confirmed, and the
// rebuild re-verifies every persisted entry against that same registry. Without
// the row the replay quarantines the entry a second time as missing_device_key,
// and the recovery reads as broken for a reason the field never has.
function drainConfirmPeer(DatabaseManager $db, int $userId, string $publicKey): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => DRAIN_PEER_DEVICE,
        'name' => 'Phone',
        'ed25519_public_key_hex' => bin2hex($publicKey),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);
}

// Minted through the production writer under the peer's own credentials, so
// the ciphertext, the epoch tag and the Ed25519 signature are the exact bytes
// a phone would put on the wire, then lifted back off the durable table and
// the local copy removed — leaving an entry this device has never seen.
function drainPeerFrame(DatabaseManager $db, int $userId, int $txnId, string $secretKey, string $publicKey): OpLogEntry
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => DRAIN_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => $secretKey,
        'publicKey' => $publicKey,
    ]);

    $writer->writeSet('transactions', $txnId, 'note', DRAIN_PEER_NOTE);

    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->firstOrFail();

    $db->connection()->table('op_log_entries')->where('id', $row->id)->delete();

    return new OpLogEntry(
        table: 'transactions',
        pk: $txnId,
        field: 'note',
        value: is_string($row->value) ? $row->value : null,
        hlcL: (int) $row->hlc_l,
        hlcC: (int) $row->hlc_c,
        deviceId: DRAIN_PEER_DEVICE,
        opType: OpType::from((string) $row->op_type),
        signature: (string) $row->signature,
        userId: $userId,
        gdkEpoch: is_numeric($row->gdk_epoch) ? (int) $row->gdk_epoch : null,
    );
}

it('projects a peer edit a locked drain could only quarantine, on the next unlocked request', function (): void {
    $user = drainUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $txnId = drainTransaction($db, $userId);
    app(EncryptionMigrationService::class)->migrate($user, $session);

    $keypair = sodium_crypto_sign_keypair();
    drainConfirmPeer($db, $userId, sodium_crypto_sign_publickey($keypair));
    $frame = drainPeerFrame(
        $db,
        $userId,
        $txnId,
        sodium_crypto_sign_secretkey($keypair),
        sodium_crypto_sign_publickey($keypair),
    );

    AppLockTestHarness::lock($session);
    (new OpLogReplayer($db, [DRAIN_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey($keypair))]))
        ->replay([$frame], $userId);

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->where('field', 'note')->count())->toBe(1);
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->where('reason', 'gdk_decrypt_failed')->count())
        ->toBeGreaterThanOrEqual(1);
    expect($db->connection()->table('transactions')->where('id', $txnId)->value('note'))->toBeNull();

    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    $this->actingAs($user)->get('/notifications')->assertOk();

    $projected = (string) $db->connection()->table('transactions')->where('id', $txnId)->value('note');
    expect($projected)->not->toBe('');
    expect($projected)->not->toBe(DRAIN_PEER_NOTE);

    // Re-resolved rather than reused: the request that ran the recovery leaves
    // its own session bound, and reading through the stale one would answer
    // "unreadable" for a row that is perfectly sealed.
    /** @var Session $reader */
    $reader = app(Session::class);
    AppLockTestHarness::unlock($reader, str_repeat("\x2a", 32));

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transactions', 'note', $projected, $userId, $reader))
        ->toBe(['value' => DRAIN_PEER_NOTE, 'decrypted' => true]);
});

// An entry sealed under an epoch whose wrap never reached this device cannot be
// opened by unlocking, by waiting, or by replaying. Before this it drove one
// full history rebuild per sync, forever, and recovered nothing.
const DRAIN_UNREACHED_EPOCH = 424242;

// Built by hand rather than through OpLogWriter, because the writer can only
// seal under an epoch this device already holds — and the whole point is an
// epoch it does not.
function drainFrameUnderAForeignEpoch(int $userId, int $txnId, string $note, string $rawKey, string $secretKey): OpLogEntry
{
    $value = app(OpLogFieldCrypto::class)->encrypt(
        json_encode($note, JSON_THROW_ON_ERROR),
        $rawKey,
        SensitiveColumnCodec::opLogAssociatedData('transactions', $txnId, 'note', DRAIN_UNREACHED_EPOCH),
    );

    $unsigned = new OpLogEntry(
        table: 'transactions',
        pk: $txnId,
        field: 'note',
        value: $value,
        hlcL: 1787413630431,
        hlcC: 0,
        deviceId: DRAIN_PEER_DEVICE,
        opType: OpType::Set,
        signature: '',
        userId: $userId,
        gdkEpoch: DRAIN_UNREACHED_EPOCH,
    );

    return new OpLogEntry(
        table: 'transactions',
        pk: $txnId,
        field: 'note',
        value: $value,
        hlcL: $unsigned->hlcL,
        hlcC: $unsigned->hlcC,
        deviceId: DRAIN_PEER_DEVICE,
        opType: OpType::Set,
        signature: (new DeviceKeySigner)->sign($unsigned->signingPayload(), $secretKey),
        userId: $userId,
        gdkEpoch: DRAIN_UNREACHED_EPOCH,
    );
}

it('stops replaying for an entry this device holds no key for, and recovers it when the key lands', function (): void {
    $user = drainUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $txnId = drainTransaction($db, $userId);
    app(EncryptionMigrationService::class)->migrate($user, $session);

    $keypair = sodium_crypto_sign_keypair();
    drainConfirmPeer($db, $userId, sodium_crypto_sign_publickey($keypair));

    $foreignKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $frame = drainFrameUnderAForeignEpoch(
        $userId,
        $txnId,
        DRAIN_PEER_NOTE,
        $foreignKey,
        sodium_crypto_sign_secretkey($keypair),
    );

    AppLockTestHarness::lock($session);
    (new OpLogReplayer($db, [DRAIN_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey($keypair))]))
        ->replay([$frame], $userId);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $quarantineAfterDrain = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count();
    expect($quarantineAfterDrain)->toBe(1);
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->value('gdk_epoch'))
        ->toBe(DRAIN_UNREACHED_EPOCH);

    $this->actingAs($user)->get('/notifications')->assertOk();

    // Looked at, found unopenable, and marked as looked-at. A replay would
    // have re-quarantined the entry and left a second row behind.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(1);
    expect($db->connection()->table('transactions')->where('id', $txnId)->value('note'))->toBeNull();

    $marks = $db->connection()->table('sync_encryption_state')->where('user_id', $userId)->first();
    expect($marks->history_reprojected_at)->not->toBeNull();
    expect($marks->reprojected_keyring_fingerprint)->not->toBeNull();

    /** @var Session $reader */
    $reader = app(Session::class);
    AppLockTestHarness::unlock($reader, str_repeat("\x2a", 32));
    expect(app(HistoryReprojector::class)->backlogState($userId, $reader, null, null))
        ->toBe(SyncBacklogState::AwaitingKey);

    // A second and third request cost nothing: the marks already cover this
    // entry and the keyring has not moved.
    $this->actingAs($user)->get('/notifications')->assertOk();
    $this->actingAs($user)->get('/notifications')->assertOk();
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(1);

    // The wrap finally lands. Nothing was discarded while it was missing, so
    // the entry projects on the first request after the keyring changes.
    app(GdkKeyringService::class)->appendEpoch(
        $userId,
        new GdkEpoch(epochId: DRAIN_UNREACHED_EPOCH, keyHex: bin2hex($foreignKey)),
        $reader,
    );

    $this->actingAs($user)->get('/notifications')->assertOk();

    $projected = (string) $db->connection()->table('transactions')->where('id', $txnId)->value('note');
    expect($projected)->not->toBe('');

    /** @var Session $after */
    $after = app(Session::class);
    AppLockTestHarness::unlock($after, str_repeat("\x2a", 32));
    expect(app(SensitiveColumnCodec::class)->decryptValue('transactions', 'note', $projected, $userId, $after))
        ->toBe(['value' => DRAIN_PEER_NOTE, 'decrypted' => true]);
});

// The narrowing has to be observable, or "we replay less" is a claim rather
// than a behaviour. A full rebuild replays every SET in the log, so a row the
// quarantine never named is the probe: it survives a narrowed replay and is
// overwritten by a whole-history one.
it('replays only the rows the quarantine names, leaving the rest of the log unreplayed', function (): void {
    $user = drainUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $txnId = drainTransaction($db, $userId);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    app(EncryptionMigrationService::class)->migrate($user, $session);

    $keypair = sodium_crypto_sign_keypair();
    drainConfirmPeer($db, $userId, sodium_crypto_sign_publickey($keypair));

    // Signed by the SAME confirmed device as the frame below, so the rebuild
    // this probe is meant to detect would genuinely apply it rather than
    // turning it away at the signature gate.
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => DRAIN_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);
    $writer->writeSet('categories', $categoryId, 'name', 'Groceries');

    $frame = drainPeerFrame(
        $db,
        $userId,
        $txnId,
        sodium_crypto_sign_secretkey($keypair),
        sodium_crypto_sign_publickey($keypair),
    );

    // Out from under the op log on purpose: only a whole-history replay puts
    // the logged name back over it.
    $db->connection()->table('categories')->where('id', $categoryId)->update(['name' => 'EDITED-OUTSIDE-THE-LOG']);

    AppLockTestHarness::lock($session);
    (new OpLogReplayer($db, [DRAIN_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey($keypair))]))
        ->replay([$frame], $userId);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $this->actingAs($user)->get('/notifications')->assertOk();

    expect($db->connection()->table('transactions')->where('id', $txnId)->value('note'))->not->toBeNull();
    expect($db->connection()->table('categories')->where('id', $categoryId)->value('name'))
        ->toBe('EDITED-OUTSIDE-THE-LOG');
});
