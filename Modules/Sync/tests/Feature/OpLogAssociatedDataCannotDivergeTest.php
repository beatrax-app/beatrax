<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The op-log associated data is authenticated, not encrypted: if the writer and
// the verifier ever spell it differently by one byte, decrypt() returns false
// and the entry is quarantined with nothing a user would ever see. These tests
// fail on that divergence instead of letting it go quiet.

function adUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function adTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'AD divergence account',
        'slug' => 'ad-divergence-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ad-divergence.csv',
        'sha256' => hash('sha256', 'ad-run-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ad-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ad merchant',
        'counterparty_name' => 'AD Merchant',
        'normalization_version' => 3,
        'description' => 'op-log AD divergence test',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

// The durable row as a sending peer reads it back. `op_log_entries.pk` is a
// VARCHAR, so the spelling of $pk is the sender's choice — which is exactly
// what the string-pk test below varies.
function adStoredEntry(DatabaseManager $db, int $userId, int|string $pk): OpLogEntry
{
    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->firstOrFail();

    return new OpLogEntry(
        table: 'transactions',
        pk: $pk,
        field: 'note',
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

function adQuarantineCount(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-09 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('spells the op-log AD as table:pk:field:epoch and renders an int pk and its string spelling as the same bytes', function (): void {
    // Every op-log entry already on disk is authenticated under this literal,
    // so a change here is a migration and not a refactor.
    expect(SensitiveColumnCodec::opLogAssociatedData('transactions', 1, 'note', 1))
        ->toBe('transactions:1:note:1');

    expect(SensitiveColumnCodec::opLogAssociatedData('transactions', '1', 'note', 1))
        ->toBe(SensitiveColumnCodec::opLogAssociatedData('transactions', 1, 'note', 1));

    // `op_log_entries.pk` is a VARCHAR and the parameter is int|string, so a
    // pk that is not a number has to survive verbatim: any numeric coercion
    // renders it as 0 and every entry under it fails to decrypt.
    expect(SensitiveColumnCodec::opLogAssociatedData('user_preferences', 'theme-dark', 'value', 3))
        ->toBe('user_preferences:theme-dark:value:3');
});

it('keeps the pk-bearing op-log AD and the pk-less projection AD as two shapes that cannot open each other', function (): void {
    /** @var OpLogFieldCrypto $crypto */
    $crypto = $this->app->make(OpLogFieldCrypto::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    $ciphertext = $crypto->encrypt(
        'a private note',
        $rawGdkKey,
        SensitiveColumnCodec::opLogAssociatedData('transactions', 1, 'note', 1),
    );

    expect($crypto->decrypt($ciphertext, $rawGdkKey, SensitiveColumnCodec::associatedData('transactions', 'note', 1)))
        ->toBeFalse();
});

it('decrypts what the writer encrypted when the entry comes back through the frame a peer would send, quarantining nothing', function (): void {
    $userId = (int) adUser('ad-divergence-wire')->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);
    $txnId = adTransaction($db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    /** @var OpLogWriter $writer */
    $writer = $this->app->make(OpLogWriter::class, [
        'deviceId' => 'ad-device-wire',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    $writer->writeSet('transactions', $txnId, 'note', 'a private note');

    // Without this the test would still pass if encryption had quietly fallen
    // back to plaintext, which is the one way an AD test passes for nothing.
    $storedValue = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)->where('field', 'note')->value('value');
    expect($storedValue)->not->toBe(json_encode('a private note'));

    $framer = new TransportFramer;
    $received = $framer->decode($framer->encode([adStoredEntry($db, $userId, $txnId)]));

    $replayer = new OpLogReplayer($db, ['ad-device-wire' => $writer->publicKeyHex()]);
    $replayer->replay($received, $userId);

    expect(adQuarantineCount($db, $userId))->toBe(0);

    $projected = $db->connection()->table('transactions')->where('id', $txnId)->value('note');
    expect($projected)->not->toBeNull();

    $decrypted = $codec->decryptRow('transactions', ['note' => $projected], $userId, $session);
    expect($decrypted['note'])->toBe('a private note');
});

it('decrypts the same entry when the peer spells the pk as a string instead of an int', function (): void {
    $userId = (int) adUser('ad-divergence-strpk')->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);
    $txnId = adTransaction($db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    /** @var OpLogWriter $writer */
    $writer = $this->app->make(OpLogWriter::class, [
        'deviceId' => 'ad-device-strpk',
        'userId' => $userId,
        'secretKey' => $secretKey,
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    $writer->writeSet('transactions', $txnId, 'note', 'another private note');

    // A peer carrying the VARCHAR pk verbatim signs that spelling, so the
    // Ed25519 gate is satisfied and the associated data is the only thing left
    // that the two sides can disagree about.
    $stub = adStoredEntry($db, $userId, (string) $txnId);
    $resigned = new OpLogEntry(
        table: $stub->table,
        pk: $stub->pk,
        field: $stub->field,
        value: $stub->value,
        hlcL: $stub->hlcL,
        hlcC: $stub->hlcC,
        deviceId: $stub->deviceId,
        opType: $stub->opType,
        signature: (new DeviceKeySigner)->sign($stub->signingPayload(), $secretKey),
        userId: $stub->userId,
        gdkEpoch: $stub->gdkEpoch,
    );

    $replayer = new OpLogReplayer($db, ['ad-device-strpk' => $writer->publicKeyHex()]);
    $replayer->replay([$resigned], $userId);

    expect(adQuarantineCount($db, $userId))->toBe(0);

    $projected = $db->connection()->table('transactions')->where('id', $txnId)->value('note');
    $decrypted = $codec->decryptRow('transactions', ['note' => $projected], $userId, $session);
    expect($decrypted['note'])->toBe('another private note');
});

it('has exactly one place that builds the op-log AD', function (): void {
    $root = dirname(__DIR__, 4);
    $hits = [];

    foreach (['Modules', 'app'] as $tree) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$tree, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $path = (string) $file;

            if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            if (preg_match('/"\{\$[^}"]+\}:\{\$[^}"]+\}:\{\$[^}"]+\}:\{\$[^}"]+\}"/', (string) file_get_contents($path)) !== 1) {
                continue;
            }

            if (! str_ends_with($path, 'Sync/Public/Services/SensitiveColumnCodec.php')) {
                $hits[] = substr($path, strlen($root) + 1);
            }
        }
    }

    expect($hits)->toBe([]);
});
