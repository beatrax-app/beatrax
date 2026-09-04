<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\OpLog\DeferredOpCaptureDrain;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Internal\OpLog\DeferredOpKind;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

const SEALED_DRAIN_NOTE = 'IBAN of the plumber, typed while the phone was locked';

// The measured case: `recurring:detect` ran at 00:00:15 on a scheduler holding
// no key, moved billing_day on six series, and the paired phone still read null
// weeks later. The command's capture is correct — this file is about what
// happens to the mutation between the event and the log.
function aDrainableUser(DatabaseManager $db): int
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'drain-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'drain-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    app()->instance(OpLogWriter::class, $writer);

    return $userId;
}

function aSeries(DatabaseManager $db, int $userId, ?int $billingDay): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Streaming',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'cluster_key' => 'streaming-'.bin2hex(random_bytes(4)),
        'billing_day' => $billingDay,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-04 00:00:15',
    ]);
}

/**
 * @return list<object>
 */
function opsFor(DatabaseManager $db, int $userId, string $table, int|string $pk): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->where('pk', (string) $pk)
        ->orderBy('id')
        ->get()
        ->all();
}

it('announces the value the row holds NOW, not the one captured while locked', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);
    $seriesId = aSeries($db, $userId, 7);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Set);

    // The reader edited the series before ever unlocking again. A queue holding
    // the captured VALUE would now announce 7 and undo their 15.
    $db->connection()->table('recurring_series')->where('id', $seriesId)->update(['billing_day' => 15]);

    expect(app(DeferredOpCaptureDrain::class)->drain($userId))->toBe(1);

    $ops = opsFor($db, $userId, 'recurring_series', $seriesId);

    expect($ops)->toHaveCount(1)
        ->and($ops[0]->op_type)->toBe(OpType::Set->value)
        ->and($ops[0]->field)->toBe('billing_day')
        ->and($ops[0]->value)->toBe('15');
});

it('retires a coordinate once its op is written', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);
    $seriesId = aSeries($db, $userId, 7);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Set);

    app(DeferredOpCaptureDrain::class)->drain($userId);

    expect($db->connection()->table('deferred_op_captures')->count())->toBe(0)
        ->and(app(DeferredOpCaptureDrain::class)->drain($userId))->toBe(0);
});

it('re-reads a whole create from the live row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);
    $seriesId = aSeries($db, $userId, 3);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);

    foreach (['detected_name', 'latest_amount_minor', 'billing_day'] as $field) {
        $queue->record($userId, 'recurring_series', $seriesId, $field, DeferredOpKind::Create);
    }

    app(DeferredOpCaptureDrain::class)->drain($userId);

    $ops = opsFor($db, $userId, 'recurring_series', $seriesId);
    $fields = array_map(static fn (object $op): string => (string) $op->field, $ops);

    expect(array_unique(array_map(static fn (object $op): string => (string) $op->op_type, $ops)))
        ->toBe([OpType::CreateRow->value])
        // withRowTimestamps adds the two the caller did not name, so a synced
        // row still reaches the peer with a birth time.
        ->and($fields)->toContain('detected_name', 'latest_amount_minor', 'billing_day', 'created_at', 'updated_at');
});

// A create whose row is gone by the time a key turns up has been superseded by
// a delete. Announcing it would resurrect a row on the peer that this device
// no longer has.
it('drops a create whose row was deleted before the drain', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);
    $seriesId = aSeries($db, $userId, 3);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', $seriesId, 'detected_name', DeferredOpKind::Create);

    $db->connection()->table('recurring_series')->where('id', $seriesId)->delete();

    expect(app(DeferredOpCaptureDrain::class)->drain($userId))->toBe(1)
        ->and(opsFor($db, $userId, 'recurring_series', $seriesId))->toBe([])
        ->and($db->connection()->table('deferred_op_captures')->count())->toBe(0);
});

// The one kind that needs no row at all: the tombstone IS the fact, and the
// row being gone is exactly the state it describes.
it('still announces a delete when the row is gone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', 991, OpLogWriter::TOMBSTONE_FIELD, DeferredOpKind::Delete);

    app(DeferredOpCaptureDrain::class)->drain($userId);

    $ops = opsFor($db, $userId, 'recurring_series', 991);

    expect($ops)->toHaveCount(1)
        ->and($ops[0]->op_type)->toBe(OpType::DeleteTombstone->value);
});

// Ordering is the whole reason the queue keeps insertion order: a set that
// reaches a peer ahead of the create naming its row is an op against a row the
// peer does not have yet.
it('emits the create of a row before the sets that followed it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);
    $seriesId = aSeries($db, $userId, 9);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', $seriesId, 'detected_name', DeferredOpKind::Create);
    $queue->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Set);

    app(DeferredOpCaptureDrain::class)->drain($userId);

    $ops = opsFor($db, $userId, 'recurring_series', $seriesId);
    $kinds = array_map(static fn (object $op): string => (string) $op->op_type, $ops);

    expect(array_search(OpType::Set->value, $kinds, true))
        ->toBeGreaterThan((int) array_search(OpType::CreateRow->value, $kinds, true));
});

// Owed, not failed. A locked device that never unlocks keeps its coordinates
// rather than retiring them against a writer that could not have signed them.
it('leaves every coordinate standing when no key is in reach', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);
    $seriesId = aSeries($db, $userId, 7);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Set);

    app()->forgetInstance(OpLogWriter::class);
    app()->bind(OpLogWriter::class, function (): OpLogWriter {
        throw new BindingResolutionException('locked');
    });

    expect(app(DeferredOpCaptureDrain::class)->drain($userId))->toBe(0)
        ->and($db->connection()->table('deferred_op_captures')->count())->toBe(1);
});

it('adds a deferred g_counter delta to what this device already published', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = aDrainableUser($db);

    $merchantId = (int) $db->connection()->table('merchants')->insertGetId([
        'user_id' => $userId,
        'name' => 'Bakery',
        'normalized_name' => 'bakery',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $categoryId = (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $memoryId = (int) $db->connection()->table('merchant_memories')->insertGetId([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => 4,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);

    foreach (range(1, 3) as $ignored) {
        $queue->record($userId, 'merchant_memories', $memoryId, 'occurrence_count', DeferredOpKind::Increment, 1);
    }

    app(DeferredOpCaptureDrain::class)->drain($userId);

    $ops = opsFor($db, $userId, 'merchant_memories', $memoryId);

    // Three occurrences the locked device counted, published as this device's
    // own running total — never as the merged column, which already holds the
    // other devices' contributions.
    expect($ops)->toHaveCount(1)
        ->and($ops[0]->value)->toBe('3');
});

// The trap a re-read walks straight into: a sealed column is CIPHERTEXT in the
// row, and OpLogWriter seals what it is handed under associated data of its
// own. Passing the stored bytes through wraps a second layer round the first,
// and the peer that unwraps the outer one projects base64 as a note.
it('re-reads a sealed column as plaintext rather than wrapping its ciphertext again', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'sealed-drain-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN sealed drain',
        'slug' => 'sealed-drain-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sealed-drain.csv',
        'sha256' => hash('sha256', 'sealed-drain-run-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-09-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $txnId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'sealed-drain-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-09-01',
        'booked_at' => '2026-09-01 10:00:00',
        'value_date' => '2026-09-01',
        'amount_minor' => -4200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4200,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'plumber',
        'note' => SEALED_DRAIN_NOTE,
        'type' => 'expense',
        'status' => 'settled',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'normalization_version' => 3,
        'fingerprint_version' => 3,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    app(EncryptionMigrationService::class)->migrate($user, $session);

    expect($db->connection()->table('transactions')->where('id', $txnId)->value('note'))
        ->not->toBe(SEALED_DRAIN_NOTE);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'sealed-drain-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    app(DeferredOpCaptures::class)->record($userId, 'transactions', $txnId, 'note', DeferredOpKind::Set);
    app(DeferredOpCaptureDrain::class)->drain($userId);

    $op = opsFor($db, $userId, 'transactions', $txnId)[0];
    $epoch = app(GdkKeyringService::class)->currentEpoch($userId, $session);

    $opened = app(OpLogFieldCrypto::class)->decrypt(
        (string) $op->value,
        sodium_hex2bin($epoch->keyHex),
        SensitiveColumnCodec::opLogAssociatedData('transactions', $txnId, 'note', $epoch->epochId),
    );

    // ONE unwrap reaches the note. A second layer would leave the inner
    // base64 sitting here instead, which is exactly what a peer would project.
    expect($op->gdk_epoch)->not->toBeNull()
        ->and($opened)->toBe(json_encode(SEALED_DRAIN_NOTE, JSON_THROW_ON_ERROR));
});

// End to end through the real driver, because a queue nothing drives is the
// same lost mutation one layer along. No screen is opened for this and no
// button is pressed: any page the reader loads carries it.
it('is drained by an ordinary request, with nothing on the page asking for it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'tail-drain-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;

    $seriesId = aSeries($db, $userId, 15);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'tail-drain-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    app(DeferredOpCaptures::class)->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Set);

    $this->actingAs($user)->get('/notifications')->assertOk();

    expect(opsFor($db, $userId, 'recurring_series', $seriesId))->toHaveCount(1)
        ->and($db->connection()->table('deferred_op_captures')->count())->toBe(0);
});
