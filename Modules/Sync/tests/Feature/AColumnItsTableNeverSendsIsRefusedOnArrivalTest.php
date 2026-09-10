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

// `users` puts thirteen preference columns on the wire and keeps the rest off,
// password and username among them. That filter runs where the op is CAPTURED,
// which is the sender's promise: a peer on a build whose list was shorter still
// emits what this one protects, and lww applies whatever arrives.

function arrivingUserField(string $field, string $value, int $userId, int $counter): OpLogEntry
{
    return new OpLogEntry(
        table: 'users',
        pk: (string) $userId,
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

function verifierForArrival(int $userId): OpLogEntryVerifier
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

it('refuses a users column its own table never puts on the wire, and takes one it does', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'device-local-arrival',
        'password' => 'the-hash-this-device-chose',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $accepted = verifierForArrival($userId)->verifyPersistAndPrepare([
        arrivingUserField('password', 'a-hash-the-peer-chose', $userId, 1),
        arrivingUserField('timezone', 'Europe/Amsterdam', $userId, 2),
    ], $userId, '2026-09-10 23:00:00');

    $refused = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->pluck('reason')
        ->all();

    expect($refused)->toBe(['device_local_column']);

    // The positive control: without it a gate that refused every users column
    // would read exactly the same way.
    expect(array_map(static fn (OpLogEntry $e): string => $e->field, $accepted))
        ->toBe(['timezone']);

    expect($db->connection()->table('users')->where('id', $userId)->value('password'))
        ->toBe('the-hash-this-device-chose');
});
