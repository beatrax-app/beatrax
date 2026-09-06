<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// `_delete_wins` is a per-table rule with a default of true, and it decides one
// thing: the exact HLC tie between a tombstone and an edit. The merge never
// asked. `tombstoneWins()` returned `>= 0` for every table, so the tie was a
// constant of the merge rather than a property of the table, and `accounts` —
// the one table that sets it false, because deleting an account takes its
// ledger with it — lost the row to a peer's delete made at the same instant.
const DWT_DEVICE = 'device-delete-wins';

/**
 * @return array{userId: int, pkHex: string, sk: string}
 */
function dwtDevice(DatabaseManager $db): array
{
    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'delete-wins-tie',
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

function dwtSignedEntry(
    string $sk,
    string $table,
    int|string $pk,
    string $field,
    ?string $value,
    int $hlcL,
    OpType $opType,
    int $userId,
): OpLogEntry {
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: $table,
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: DWT_DEVICE,
        opType: $opType,
        signature: $signature,
        userId: $userId,
    );

    return $make((new DeviceKeySigner)->sign($make('')->signingPayload(), $sk));
}

function dwtAccount(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Tie account',
        'slug' => 'tie-account',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ASNB0000000001',
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);
}

function dwtCategory(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Tie category',
        'slug' => 'tie-category',
        'kind' => 'expense',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);
}

// `accounts` sets `_delete_wins => false`, and the tie is the whole of what
// that decides.
it('keeps an account whose edit ties the tombstone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = dwtDevice($db);
    $accountId = dwtAccount($db, $device['userId']);

    $tomb = dwtSignedEntry($device['sk'], 'accounts', $accountId, '__tombstone__',
        null, 5000, OpType::DeleteTombstone, $device['userId']);
    $edit = dwtSignedEntry($device['sk'], 'accounts', $accountId, 'name',
        json_encode('renamed at the same instant'), 5000, OpType::Set, $device['userId']);

    (new OpLogReplayer($db, [DWT_DEVICE => $device['pkHex']]))
        ->replay([$tomb, $edit], $device['userId']);

    $row = $db->connection()->table('accounts')->where('id', $accountId)->first();

    expect($row)->not->toBeNull('an account was deleted by a tombstone that only tied its edit')
        ->and($row->name)->toBe('renamed at the same instant');
});

// The positive control, and the default the requirement names: every other
// table keeps the tie going to the tombstone.
it('deletes a category whose edit ties the tombstone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = dwtDevice($db);
    $categoryId = dwtCategory($db, $device['userId']);

    $tomb = dwtSignedEntry($device['sk'], 'categories', $categoryId, '__tombstone__',
        null, 5000, OpType::DeleteTombstone, $device['userId']);
    $edit = dwtSignedEntry($device['sk'], 'categories', $categoryId, 'name',
        json_encode('renamed at the same instant'), 5000, OpType::Set, $device['userId']);

    (new OpLogReplayer($db, [DWT_DEVICE => $device['pkHex']]))
        ->replay([$tomb, $edit], $device['userId']);

    expect($db->connection()->table('categories')->where('id', $categoryId)->exists())
        ->toBeFalse('the default is that a tie goes to the tombstone, and it no longer does');
});

// The rule governs the tie and nothing else: a tombstone that is strictly later
// still takes the account, or "delete does not win" would read as "an account
// cannot be deleted from another device at all".
it('still deletes an account whose tombstone outranks the edit', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = dwtDevice($db);
    $accountId = dwtAccount($db, $device['userId']);

    $edit = dwtSignedEntry($device['sk'], 'accounts', $accountId, 'name',
        json_encode('renamed first'), 4000, OpType::Set, $device['userId']);
    $tomb = dwtSignedEntry($device['sk'], 'accounts', $accountId, '__tombstone__',
        null, 6000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [DWT_DEVICE => $device['pkHex']]))
        ->replay([$edit, $tomb], $device['userId']);

    expect($db->connection()->table('accounts')->where('id', $accountId)->exists())
        ->toBeFalse('a strictly later tombstone no longer wins, so the rule is being read as more than the tie');
});
