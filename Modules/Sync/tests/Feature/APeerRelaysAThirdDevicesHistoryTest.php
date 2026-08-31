<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\CatchUpDelta;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpCursors;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\PeerCatchUpWatermarks;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// A peer sends what OTHER installs wrote as well as its own, so "delivered by"
// and "authored by" are different questions. One scalar per peer answered only
// the first: a third device that had been offline for months pushed its older
// ops into the relay, and every one of them sat below the cursor for good.

function relayUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'relay-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function relayDevice(DatabaseManager $db, int $userId, string $deviceId): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function relayOp(DatabaseManager $db, int $userId, string $author, int $hlcL, string $pk): OpLogEntry
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => $author,
        'table_name' => 'merchants',
        'pk' => $pk,
        'field' => 'name',
        'op_type' => OpType::Set->value,
        'value' => json_encode('name '.$pk, JSON_THROW_ON_ERROR),
        'hlc_l' => $hlcL,
        'hlc_c' => 0,
        'signature' => str_repeat('a', 128),
        'recorded_at' => '2026-06-14 10:00:00',
    ]);

    return new OpLogEntry(
        table: 'merchants', pk: $pk, field: 'name', value: null,
        hlcL: $hlcL, hlcC: 0, deviceId: $author,
        opType: OpType::Set, signature: 'sig', userId: $userId,
    );
}

/**
 * @return list<string>
 */
function relayAuthorsIn(CatchUpDelta $delta): array
{
    $framer = new TransportFramer;
    $authors = [];

    foreach ($delta as $frame) {
        foreach ($framer->decode($frame) as $entry) {
            $authors[] = $entry->deviceId;
        }
    }

    return $authors;
}

it('asks a relay for a third device history that predates what the relay already delivered', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = relayUser($db);

    relayDevice($db, $userId, 'dev-A');
    relayDevice($db, $userId, 'dev-C');
    relayDevice($db, $userId, 'dev-B-relay');

    // Step 1: the relay holds only dev-A's op and delivers it. Delivered by
    // dev-B-relay, authored by dev-A — the distinction the whole bug turns on.
    $fromA = relayOp($db, $userId, 'dev-A', 1000, '1');
    (new PeerCatchUpWatermarks($db))->advance($userId, 'dev-B-relay', [$fromA], '2026-06-14 10:00:00');

    // Step 2: dev-C, offline until now, syncs its OLDER op into the relay.
    relayOp($db, $userId, 'dev-C', 900, '2');

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $request = $exchanger->buildRequest($userId, 'device-d', 'dev-B-relay');

    $authors = relayAuthorsIn($exchanger->opsAfterWatermark($userId, $exchanger->cursorsFrom($request)));

    expect($authors)->toContain('dev-C')
        ->and($authors)->not->toContain('dev-A');
});

it('never walks one author cursor backwards when an older op of that same author arrives late', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = relayUser($db);

    $watermarks = new PeerCatchUpWatermarks($db);

    $entryAt = static fn (string $author, int $hlcL): OpLogEntry => new OpLogEntry(
        table: 'merchants', pk: 1, field: 'name', value: null,
        hlcL: $hlcL, hlcC: 0, deviceId: $author,
        opType: OpType::Set, signature: 'sig', userId: $userId,
    );

    $watermarks->advance($userId, 'dev-B-relay', [$entryAt('dev-A', 500)], '2026-06-14 10:00:00');
    $watermarks->advance($userId, 'dev-B-relay', [$entryAt('dev-A', 100)], '2026-06-14 10:00:01');

    expect($watermarks->for($userId, 'dev-B-relay')->for('dev-A'))->toBe([500, 0]);
});

it('leaves one author cursor alone when the same peer delivers another author', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = relayUser($db);

    $watermarks = new PeerCatchUpWatermarks($db);

    $entryAt = static fn (string $author, int $hlcL): OpLogEntry => new OpLogEntry(
        table: 'merchants', pk: 1, field: 'name', value: null,
        hlcL: $hlcL, hlcC: 0, deviceId: $author,
        opType: OpType::Set, signature: 'sig', userId: $userId,
    );

    $watermarks->advance($userId, 'dev-B-relay', [$entryAt('dev-A', 1000)], '2026-06-14 10:00:00');
    $watermarks->advance($userId, 'dev-B-relay', [$entryAt('dev-C', 900)], '2026-06-14 10:00:01');

    $cursors = $watermarks->for($userId, 'dev-B-relay');

    expect($cursors->for('dev-A'))->toBe([1000, 0])
        ->and($cursors->for('dev-C'))->toBe([900, 0]);
});

it('sends every op of an author the peer named no cursor for', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = relayUser($db);

    relayDevice($db, $userId, 'dev-A');
    relayDevice($db, $userId, 'dev-C');

    relayOp($db, $userId, 'dev-A', 1000, '1');
    relayOp($db, $userId, 'dev-C', 900, '2');

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $cursors = PeerCatchUpCursors::fromWire([['device_id' => 'dev-A', 'hlc_l' => 1000, 'hlc_c' => 0]]);

    $authors = relayAuthorsIn($exchanger->opsAfterWatermark($userId, $cursors));

    expect($authors)->toBe(['dev-C']);
});

it('clamps a negative cursor rather than matching the entire op log', function (): void {
    $cursors = PeerCatchUpCursors::fromWire([['device_id' => 'dev-A', 'hlc_l' => -5, 'hlc_c' => -1]]);

    expect($cursors->for('dev-A'))->toBe([0, 0]);
});
