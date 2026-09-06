<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// A stored null means "no value" and nothing else. What separates a field the
// reader cleared from a row the reader deleted is the OPERATION KIND — `set`
// against `delete_tombstone` — never the value, because both put a null on the
// wire. The distinction was built correctly and had no case of its own, which
// is the difference between a property that holds and a property that is
// checked: a replayer that started reading a null value as a tombstone would
// delete rows on every clear, and every existing case would still pass.
const FCN_DEVICE = 'device-null-vs-tombstone';

/**
 * @return array{userId: int, pkHex: string, sk: string}
 */
function fcnDevice(DatabaseManager $db): array
{
    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'null-vs-tombstone',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    return [
        'userId' => $userId,
        'pkHex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        'sk' => sodium_crypto_sign_secretkey($keypair),
    ];
}

function fcnSignedEntry(
    string $sk,
    int|string $pk,
    string $field,
    ?string $value,
    int $hlcL,
    OpType $opType,
    int $userId,
): OpLogEntry {
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: FCN_DEVICE,
        opType: $opType,
        signature: $signature,
        userId: $userId,
    );

    return $make((new DeviceKeySigner)->sign($make('')->signingPayload(), $sk));
}

function fcnCategory(DatabaseManager $db, int $userId, string $slug, ?int $parentId): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'parent_id' => $parentId,
        'name' => 'Child of something',
        'slug' => $slug,
        'kind' => 'expense',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);
}

it('clears the field and keeps the row when a set carries null', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = fcnDevice($db);
    $parentId = fcnCategory($db, $device['userId'], 'fcn-parent', null);
    $childId = fcnCategory($db, $device['userId'], 'fcn-child', $parentId);

    $clear = fcnSignedEntry($device['sk'], $childId, 'parent_id', json_encode(null), 5000, OpType::Set, $device['userId']);

    (new OpLogReplayer($db, [FCN_DEVICE => $device['pkHex']]))
        ->replay([$clear], $device['userId']);

    $row = $db->connection()->table('categories')->where('id', $childId)->first();

    expect($row)->not->toBeNull('a null value was read as a delete, so clearing a field removes the row')
        ->and($row->parent_id)->toBeNull('the field was not cleared, so a stored null does not mean "no value"');
});

// The other half, and the one that gives the case above its meaning: the same
// row, the same null on the wire, and only the operation kind different.
it('removes the row when a tombstone carries the same null', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = fcnDevice($db);
    $parentId = fcnCategory($db, $device['userId'], 'fcn-parent', null);
    $childId = fcnCategory($db, $device['userId'], 'fcn-child', $parentId);

    $tomb = fcnSignedEntry($device['sk'], $childId, '__tombstone__', null, 5000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [FCN_DEVICE => $device['pkHex']]))
        ->replay([$tomb], $device['userId']);

    expect($db->connection()->table('categories')->where('id', $childId)->exists())
        ->toBeFalse('a tombstone no longer deletes, so the two operations have stopped being distinguishable at all');
});

// Neither is decided by the value: the two entries above put the SAME payload
// on the wire. If they ever stop differing in `opType`, the distinction the
// requirement names has been lost whatever the rows happen to do.
it('puts the same null on the wire for both, and differs only in the operation kind', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = fcnDevice($db);

    $clear = fcnSignedEntry($device['sk'], 1, 'parent_id', json_encode(null), 5000, OpType::Set, $device['userId']);
    $tomb = fcnSignedEntry($device['sk'], 1, '__tombstone__', null, 5000, OpType::DeleteTombstone, $device['userId']);

    expect($clear->value)->toBe('null')
        ->and($tomb->value)->toBeNull()
        ->and($clear->opType)->not->toBe($tomb->opType)
        ->and($clear->opType)->toBe(OpType::Set)
        ->and($tomb->opType)->toBe(OpType::DeleteTombstone);
});
