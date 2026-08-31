<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// A rebuild replays the whole history at once; incremental replay sees it a
// frame at a time. Both have to land on the same row, which only works because
// the keyring is append-only AND because a frame is not the set a strategy
// resolves over. Comparing a rebuild against a column no replay ever wrote
// proved neither half of that.

function convergenceUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function convergencePeerDevice(DatabaseManager $db, int $userId, string $deviceId): int
{
    return (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

/**
 * @return array{0: int, 1: int} [transactionId, categoryId]
 */
function convergenceLedgerRow(DatabaseManager $db, int $userId): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Convergence account',
        'slug' => 'convergence-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00CONV'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/convergence-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'convergence-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $categoryId = (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $txnId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'convergence-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1,
        'currency' => 'EUR',
        'settled_amount_minor' => -1,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'convergence fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return [$txnId, $categoryId];
}

// The row as a reader sees it: the sensitive column decrypted, so two
// projections written under different epochs compare as the same value rather
// than as two different ciphertexts of it.
/**
 * @return array{note: string, amount_minor: int, category_id: int}
 */
function convergenceProjection(DatabaseManager $db, SensitiveColumnCodec $codec, Session $session, int $userId, int $txnId): array
{
    $row = $db->connection()->table('transactions')->where('id', $txnId)->first();
    $stored = is_string($row->note ?? null) ? $row->note : '';

    return [
        'note' => $codec->decryptValue('transactions', 'note', $stored, $userId, $session)['value'],
        'amount_minor' => (int) ($row->amount_minor ?? 0),
        'category_id' => (int) ($row->category_id ?? 0),
    ];
}

it('a full OpLogRebuilder rebuild after multiple GDK rotations converges to the same projection as incremental replay', function (): void {
    $user = convergenceUser('convergence-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $keyring->generateAndPersist($userId, $session);

    [$txnId, $categoryId] = convergenceLedgerRow($db, $userId);

    // The acting device needs a real on-disk identity, because the rotation
    // loads it to sign each fan-out wrap.
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist($userId, $session);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $keypair = sodium_crypto_sign_keypair();
    $writer = static function () use ($userId, $self, $keypair): OpLogWriter {
        // Rebuilt after each rotation so it seals under the epoch that is
        // current at the time of the write, never a cached one.
        /** @var OpLogWriter $built */
        $built = app(OpLogWriter::class, [
            'deviceId' => $self->deviceId,
            'userId' => $userId,
            'secretKey' => sodium_crypto_sign_secretkey($keypair),
            'publicKey' => sodium_crypto_sign_publickey($keypair),
        ]);

        return $built;
    };

    // Epoch 1, then a rotation, then epoch 2, then a SECOND rotation, then
    // epoch 3 — so the history spans three keys and no single one opens it.
    $first = $writer();
    $first->writeSet('transactions', $txnId, 'note', 'written under epoch 1');
    $first->writeSet('transactions', $txnId, 'amount_minor', -1111);

    $rotation->rotateAndRevoke($userId, convergencePeerDevice($db, $userId, 'removed-one'), $session);
    $second = $writer();
    $second->writeSet('transactions', $txnId, 'note', 'written under epoch 2');
    $second->writeSet('transactions', $txnId, 'category_id', $categoryId);

    $rotation->rotateAndRevoke($userId, convergencePeerDevice($db, $userId, 'removed-two'), $session);
    $third = $writer();
    $third->writeSet('transactions', $txnId, 'note', 'written under epoch 3');
    $third->writeSet('transactions', $txnId, 'amount_minor', -9999);

    $deviceKeys = [$self->deviceId => $third->publicKeyHex()];

    // Read back out of the log, which is what the transport delivers:
    // ciphertext and epoch tag intact, three epochs deep.
    $stored = (new PersistedOpLogEntries($db))->forRows($userId, [
        ['table' => 'transactions', 'pk' => (string) $txnId],
    ]);

    // Incremental: one op per replay() call, NEWEST FIRST, which is the order a
    // catch-up frame boundary is free to produce and the order that used to
    // leave the oldest op holding the column.
    $replayer = new OpLogReplayer($db, $deviceKeys);
    foreach (array_reverse($stored) as $entry) {
        $replayer->replay([$entry], $userId);
    }

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $afterIncremental = convergenceProjection($db, $codec, $session, $userId, $txnId);

    expect($afterIncremental['note'])->toBe('written under epoch 3')
        ->and($afterIncremental['amount_minor'])->toBe(-9999)
        ->and($afterIncremental['category_id'])->toBe($categoryId);

    /** @var OpLogRebuilder $rebuilder */
    $rebuilder = $this->app->make(OpLogRebuilder::class);
    $rebuilder->rebuild($userId);

    expect(convergenceProjection($db, $codec, $session, $userId, $txnId))->toBe($afterIncremental);

    // Nothing may quarantine as undecryptable: every historical epoch has to
    // still resolve, on both paths.
    $quarantinedAfterRebuild = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();

    expect($quarantinedAfterRebuild)->toBe(0);
});

it('lands on the same projection whichever order the frames arrive in', function (): void {
    $user = convergenceUser('convergence-order-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $keyring->generateAndPersist($userId, $session);

    [$txnId] = convergenceLedgerRow($db, $userId);

    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist($userId, $session);

    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $self->deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    $writer->writeSet('transactions', $txnId, 'note', 'oldest');
    $writer->writeSet('transactions', $txnId, 'note', 'middle');
    $writer->writeSet('transactions', $txnId, 'note', 'newest');

    $stored = (new PersistedOpLogEntries($db))->forRows($userId, [
        ['table' => 'transactions', 'pk' => (string) $txnId],
    ]);

    $replayer = new OpLogReplayer($db, [$self->deviceId => $writer->publicKeyHex()]);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    foreach ([[2, 0, 1], [1, 2, 0], [0, 1, 2]] as $order) {
        foreach ($order as $index) {
            /** @var OpLogEntry $entry */
            $entry = $stored[$index];
            $replayer->replay([$entry], $userId);
        }

        expect(convergenceProjection($db, $codec, $session, $userId, $txnId)['note'])->toBe('newest');
    }
});
