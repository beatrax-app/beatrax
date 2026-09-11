<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Merge\OpLogEntryVerifier;
use Modules\Sync\Internal\Merge\OpLogQuarantine;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\PriorAuthorship;
use Modules\Sync\Internal\Merge\RegisteredColumns;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// A create seeds the row's id from the op and its owner from the session, then
// re-forces both after the field loop. A Set has no such loop: it writes the
// column it names, and `user_id` names which household member holds the row.

// 512 create ops on the live desktop carry `user_id` and 8 carry `id`, so the
// column cannot be refused outright. Not one of them is a Set.
function peerSet(string $table, string $pk, string $field, string $value, int $userId, int $counter): OpLogEntry
{
    return new OpLogEntry(
        table: $table,
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: 1789000000000 + $counter,
        hlcC: 0,
        deviceId: OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID,
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );
}

function verifierForSet(int $userId): OpLogEntryVerifier
{
    return new OpLogEntryVerifier(
        db: app(DatabaseManager::class),
        rules: new MergeRulesRegistry,
        columns: app(RegisteredColumns::class),
        sensitiveFields: app(SensitiveFieldRegistry::class),
        deviceKeys: [],
        deviceKeysUserId: $userId,
        signer: app(DeviceKeySigner::class),
        fieldCrypto: null,
        keyringService: null,
        session: null,
        quarantine: app(OpLogQuarantine::class),
        priorAuthorship: app(PriorAuthorship::class),
    );
}

function setFixtureUser(string $username): int
{
    return (int) app(DatabaseManager::class)->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => 'fixture-hash',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function setFixtureAccount(int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return (int) app(DatabaseManager::class)->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN set', 'slug' => 'set-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

it('refuses a set that names the owner of the row, and takes one that names an ordinary column', function (): void {
    $db = app(DatabaseManager::class);
    $userId = setFixtureUser('set-owner-'.bin2hex(random_bytes(3)));
    $accountId = setFixtureAccount($userId);

    $accepted = verifierForSet($userId)->verifyPersistAndPrepare([
        peerSet('accounts', (string) $accountId, 'user_id', '2', $userId, 1),
        peerSet('accounts', (string) $accountId, 'name', '"Renamed by the peer"', $userId, 2),
    ], $userId, '2026-09-11 09:00:00');

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all())
        ->toBe(['device_local_column']);

    // Without this the same verdict would be read off a gate that refused
    // every column of the table.
    expect(array_map(static fn (OpLogEntry $e): string => $e->field, $accepted))
        ->toBe(['name']);
});

it('refuses a set that names the row itself', function (): void {
    $userId = setFixtureUser('set-id-'.bin2hex(random_bytes(3)));
    $accountId = setFixtureAccount($userId);

    $accepted = verifierForSet($userId)->verifyPersistAndPrepare([
        peerSet('accounts', (string) $accountId, 'id', '999999', $userId, 3),
    ], $userId, '2026-09-11 09:00:00');

    expect($accepted)->toBe([])
        ->and(app(DatabaseManager::class)->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)->pluck('reason')->all())
        ->toBe(['device_local_column']);
});

it('still takes a create that carries the two columns, because ours do', function (): void {
    $userId = setFixtureUser('create-carries-'.bin2hex(random_bytes(3)));

    $create = static fn (string $field, string $value, int $counter): OpLogEntry => new OpLogEntry(
        table: 'accounts',
        pk: '4242',
        field: $field,
        value: $value,
        hlcL: 1789000000000 + $counter,
        hlcC: 0,
        deviceId: OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID,
        opType: OpType::CreateRow,
        signature: '',
        userId: $userId,
    );

    $accepted = verifierForSet($userId)->verifyPersistAndPrepare([
        $create('user_id', (string) $userId, 4),
        $create('name', '"From the peer"', 5),
    ], $userId, '2026-09-11 09:00:00');

    expect(array_map(static fn (OpLogEntry $e): string => $e->field, $accepted))
        ->toBe(['user_id', 'name'])
        ->and(app(DatabaseManager::class)->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)->count())
        ->toBe(0);
});
