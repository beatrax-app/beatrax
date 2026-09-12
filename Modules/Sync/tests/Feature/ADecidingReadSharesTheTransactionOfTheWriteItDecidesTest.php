<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Internal\Clock\RemoteClockAdvance;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\PeerCatchUpWatermarks;

uses(RefreshDatabase::class);

// Four values are read, computed on, and written back. The connection runs
// with transaction_mode IMMEDIATE, so a read taken inside the transaction that
// writes it back cannot be invalidated by another process — and one taken
// outside it can, which is what each of these did.

// Depth is measured against the level standing when the work begins, never
// against zero: RefreshDatabase already holds one transaction open, so a
// statement issued with no transaction of its own still reports level 1.
/**
 * @param  Closure(): void  $work
 * @return list<array{sql: string, depth: int}>
 */
function recordedStatements(DatabaseManager $db, Closure $work): array
{
    $baseline = $db->connection()->transactionLevel();
    $seen = [];

    DB::listen(function (QueryExecuted $q) use ($db, $baseline, &$seen): void {
        $seen[] = [
            'sql' => strtolower($q->sql),
            'depth' => $db->connection()->transactionLevel() - $baseline,
        ];
    });

    $work();

    return $seen;
}

// Where the named statement sits in the recorded order, or -1 when it never
// ran. Position and not depth, because two statements can share a table and a
// depth and still be the wrong one: hlc_clock_state is read once to allocate a
// stamp and again by the upsert that stores it, and only the order tells them apart.
/**
 * @param  list<array{sql: string, depth: int}>  $statements
 * @param  list<string>  $needles
 */
function positionOfStatement(array $statements, string $verb, array $needles): int
{
    foreach ($statements as $position => $statement) {
        if (! str_starts_with($statement['sql'], $verb)) {
            continue;
        }

        foreach ($needles as $needle) {
            if (! str_contains($statement['sql'], $needle)) {
                continue 2;
            }
        }

        return $position;
    }

    return -1;
}

/**
 * @param  list<array{sql: string, depth: int}>  $statements
 * @param  list<string>  $needles
 */
function depthOfStatement(array $statements, string $verb, array $needles): int
{
    $position = positionOfStatement($statements, $verb, $needles);

    return $position < 0 ? -1 : $statements[$position]['depth'];
}

function transactedUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'txn-read-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function transactedWriter(int $userId, string $deviceId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

it('allocates an op stamp from hlc_clock_state inside the transaction that inserts the op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = transactedUser($db);
    $writer = transactedWriter($userId, 'device-stamping');

    $statements = recordedStatements($db, function () use ($writer): void {
        $writer->writeSet('merchants', 5, 'name', 'Bakery');
    });

    $read = positionOfStatement($statements, 'select', ['hlc_clock_state']);
    $insert = positionOfStatement($statements, 'insert', ['op_log_entries']);

    // Depth first: a stamp allocated before the transaction opens and passed
    // in satisfies every other shape this file could check, and reports the
    // read at the baseline. Then position, because the upsert that follows the
    // insert reads the same table and would answer the depth question for it.
    expect($read)->toBeGreaterThanOrEqual(0)
        ->and($insert)->toBeGreaterThan($read)
        ->and(depthOfStatement($statements, 'select', ['hlc_clock_state']))->toBeGreaterThan(0)
        ->and(depthOfStatement($statements, 'insert', ['op_log_entries']))
        ->toBe(depthOfStatement($statements, 'select', ['hlc_clock_state']));
});

it('reads this device running g_counter total inside the transaction that publishes the next one', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = transactedUser($db);
    $writer = transactedWriter($userId, 'device-counting');

    $writer->writeIncrement('merchant_memories', 9, 'occurrence_count', 1);

    $statements = recordedStatements($db, function () use ($writer): void {
        $writer->writeIncrement('merchant_memories', 9, 'occurrence_count', 1);
    });

    $read = depthOfStatement($statements, 'select', ['op_log_entries', 'limit 1']);
    $insert = depthOfStatement($statements, 'insert', ['op_log_entries']);

    expect($read)->toBeGreaterThan(0)
        ->and($insert)->toBeGreaterThanOrEqual($read);

    // The published total still rises by exactly the delta asked for.
    $latest = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('field', 'occurrence_count')
        ->orderByDesc('hlc_l')
        ->orderByDesc('hlc_c')
        ->value('value');

    expect(json_decode((string) $latest, true))->toBe(2);
});

it('compares the absorbed peer clock against hlc_clock_state inside the transaction that replaces it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = transactedUser($db);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'device-absorbing',
        'name' => 'This desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 1,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $remote = new OpLogEntry(
        table: 'merchants',
        pk: 5,
        field: 'name',
        value: json_encode('Bakery', JSON_THROW_ON_ERROR),
        hlcL: 9_000_000_000_000,
        hlcC: 4,
        deviceId: 'some-peer',
        opType: OpType::Set,
        signature: 'unverified-here',
        userId: $userId,
    );

    $advance = new RemoteClockAdvance($db);

    $statements = recordedStatements($db, function () use ($advance, $remote, $userId): void {
        $advance->absorb([$remote], $userId, '2026-06-14 10:00:00');
    });

    $read = depthOfStatement($statements, 'select', ['hlc_clock_state']);

    expect($read)->toBeGreaterThan(0);

    $state = $db->connection()->table('hlc_clock_state')
        ->where('user_id', $userId)->where('device_id', 'device-absorbing')->first();

    expect((int) $state->last_l)->toBe(9_000_000_000_000)
        ->and((int) $state->last_c)->toBe(5);
});

it('reads the held per-author cursor inside the transaction that advances it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = transactedUser($db);

    $delivered = [new OpLogEntry(
        table: 'merchants',
        pk: 5,
        field: 'name',
        value: json_encode('Bakery', JSON_THROW_ON_ERROR),
        hlcL: 4_000,
        hlcC: 2,
        deviceId: 'author-one',
        opType: OpType::Set,
        signature: 'unverified-here',
        userId: $userId,
    )];

    $watermarks = new PeerCatchUpWatermarks($db);

    $statements = recordedStatements($db, function () use ($watermarks, $delivered, $userId): void {
        $watermarks->advance($userId, 'relaying-peer', $delivered, '2026-06-14 10:00:00');
    });

    $read = depthOfStatement($statements, 'select', ['sync_peer_catch_up_state']);

    expect($read)->toBeGreaterThan(0);

    expect($watermarks->for($userId, 'relaying-peer')->for('author-one'))->toBe([4_000, 2]);
});
