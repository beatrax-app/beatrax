<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
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

/** @return array{0: int, 1: int} */
function backfillCryptoAccount(DatabaseManager $db, int $userId): array
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN crypto '.$suffix,
        'slug' => 'crypto-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(substr(md5($suffix), 0, 8)),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/crypto-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'crypto-run-'.$suffix),
        'uploaded_at' => '2026-06-15 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    return [$accountId, $runId];
}

/** @param array<string, mixed> $sensitive */
function backfillCryptoTransaction(DatabaseManager $db, int $userId, int $accountId, int $runId, array $sensitive): int
{
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'crypto-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 10:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => 250000,
        'currency' => 'EUR',
        'settled_amount_minor' => 250000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'mijnwerkgever bv',
        'normalization_version' => 3,
        'type' => 'income',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
        ...$sensitive,
    ]);
}

function backfillCryptoReplay(DatabaseManager $db, int $userId, string $deviceId, string $publicKeyHex): void
{
    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderBy('hlc_l')
        ->orderBy('hlc_c')
        ->get();

    $entries = [];
    foreach ($rows as $row) {
        $entries[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: $userId,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    (new OpLogReplayer($db, [$deviceId => $publicKeyHex]))->replay($entries, $userId);
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
    $epoch = $keyring->generateAndPersist($user->id, $session);

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

    // Epoch ids are minted rather than counted, so the op must carry the id
    // this keyring actually holds — not the number it used to start from.
    expect($entry)->not->toBeNull()
        ->and((int) $entry->gdk_epoch)->toBe($epoch->epochId);

    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);
    $epochKey = sodium_hex2bin($keyring->loadKeyring($user->id, $session)->keyFor($epoch->epochId) ?? '');

    $once = $crypto->decrypt(
        (string) $entry->value,
        $epochKey,
        "counterparties:{$cpId}:display_name:{$epoch->epochId}",
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

it('leaves a sensitive column readable after a peer has replayed it', function (): void {
    /*
     * The tests above stop at the op: they unwrap its value and check the name
     * is there. Nothing checked the other end — that once a peer REPLAYS that
     * op, the column it writes still reads back as the name.
     *
     * That is the invariant the phone actually depends on, and it is the one
     * that broke: transactions.counterparty_name arrived wrapped one layer too
     * many, the read-side decrypt peeled off the outer layer, and the payee
     * column on the main screen rendered as base64 for every row.
     */
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = backfillCryptoUser();
    $userId = (int) $user->id;
    $keyring->generateAndPersist($userId, $session);

    [$accountId, $runId] = backfillCryptoAccount($db, $userId);

    // Written the way the real create path writes it: encrypted at rest under
    // the column associated data before it ever touches disk.
    $attrs = $codec->encryptAttrs('transactions', [
        'counterparty_name' => 'MijnWerkgever BV',
        'description' => 'Salaris augustus',
    ], $userId, $session);

    expect($attrs['counterparty_name'])->not->toBe('MijnWerkgever BV', 'fixture must be encrypted at rest');

    $txnId = backfillCryptoTransaction($db, $userId, $accountId, $runId, $attrs);

    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = $this->app->make(OpLogWriter::class, [
        'deviceId' => 'crypto-source',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = $this->app->make(OpLogBackfiller::class);
    $backfiller->backfill($userId, $writer);

    // Stand in for the receiving device: the same signed history replayed onto
    // a database that no longer holds the row.
    $db->connection()->table('transactions')->where('user_id', $userId)->delete();

    backfillCryptoReplay($db, $userId, 'crypto-source', sodium_bin2hex($publicKey));

    $stored = $db->connection()->table('transactions')->where('id', $txnId)->first();
    expect($stored)->not->toBeNull('the replay did not rebuild the row at all');

    $read = $codec->decryptValue(
        'transactions',
        'counterparty_name',
        (string) $stored->counterparty_name,
        $userId,
        $session,
    );

    expect($read['decrypted'])->toBeTrue('the replayed column could not be decrypted at all');
    expect($read['value'])->toBe(
        'MijnWerkgever BV',
        'one unwrap did not land on the name — the column is wrapped more than once'
    );
});

it('refuses to wrap a sensitive column that is already ciphertext', function (): void {
    /*
     * A column wrapped twice decrypts once to base64 and renders as a blob of
     * characters on the main screen — and the read side cannot tell that from
     * a value it merely lacks the key for, so it stays there looking like
     * corruption with nothing reporting it.
     *
     * Every write of a sensitive column funnels through encryptValue(), which
     * makes it the one place a second wrapper can be refused outright.
     */
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = backfillCryptoUser();
    $userId = (int) $user->id;
    $keyring->generateAndPersist($userId, $session);

    $once = $codec->encryptValue('transactions', 'counterparty_name', 'MijnWerkgever BV', $userId, $session);
    expect($once)->not->toBe('MijnWerkgever BV', 'the fixture is not encrypted at all');

    $twice = $codec->encryptValue('transactions', 'counterparty_name', $once, $userId, $session);

    expect($twice)->toBe($once, 'a second wrapper went on and the column now reads as base64');

    $read = $codec->decryptValue('transactions', 'counterparty_name', $twice, $userId, $session);

    expect($read['decrypted'])->toBeTrue();
    expect($read['value'])->toBe('MijnWerkgever BV', 'one unwrap did not land on the name');
});
