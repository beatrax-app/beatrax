<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * INFO hardening — the per-table COLUMN allow-list in OpLogEntryVerifier.
 *
 * The table name was already gated by MergeRulesRegistry, but the field name
 * from a (signature-verified, paired-device) op-log entry flowed straight into
 * a query-builder update()/insert() column position. That is not injection —
 * identifiers are quoted and the entry is Ed25519-gated — but an entry naming a
 * column the table does not have used to fail only at the DB. These tests prove
 * it now quarantines EARLY as unknown_column (mirroring the unknown_table gate),
 * while a SET on a real column still applies untouched.
 */

const COL_ALLOW_DEVICE = 'device-col-allow';

/**
 * @return array{userId: int, pkHex: string, sk: string}
 */
function colAllowDevice(DatabaseManager $db): array
{
    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'col-allow-'.bin2hex(random_bytes(4)),
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

// Signs a real Ed25519 op so it clears the replayer's verification gate: the
// signature covers the entry's own payload, so it is built twice — once to
// derive the payload, then again carrying the signature.
function colAllowSignedEntry(
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
        deviceId: COL_ALLOW_DEVICE,
        opType: $opType,
        signature: $signature,
        userId: $userId,
    );

    return $make((new DeviceKeySigner)->sign($make('')->signingPayload(), $sk));
}

it('quarantines a SET whose field is not a real column of the registered table (unknown_column), never applying it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = colAllowDevice($db);

    // transactions IS registered, the signature IS valid, and the device key IS
    // known — only the field is bogus, so the column gate is the sole thing that
    // can reject this.
    $entry = colAllowSignedEntry(
        $device['sk'],
        'transactions',
        1,
        'not_a_real_column',
        json_encode('anything'),
        1000,
        OpType::Set,
        $device['userId'],
    );

    (new OpLogReplayer($db, [COL_ALLOW_DEVICE => $device['pkHex']]))
        ->replay([$entry], $device['userId']);

    // Quarantined for the new reason, and never persisted to the authoritative
    // op_log_entries table.
    expect($db->connection()->table('op_log_quarantine')->where('reason', 'unknown_column')->count())
        ->toBe(1, 'an unregistered column must quarantine as unknown_column');
    expect($db->connection()->table('op_log_entries')->where('field', 'not_a_real_column')->count())
        ->toBe(0, 'a bogus-column entry must never reach op_log_entries');
});

it('still applies a SET on a real column of a registered table (the gate does not over-reject)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = colAllowDevice($db);

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $device['userId'],
        'name' => 'Original',
        'slug' => 'col-allow-acct',
        'kind' => 'bank',
        'iban' => 'NL00COLALLOW',
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    // 'name' is both a registered LWW field and a real column, so it clears the
    // column gate and the LWW merge writes it.
    $entry = colAllowSignedEntry(
        $device['sk'],
        'accounts',
        $accountId,
        'name',
        json_encode('Renamed'),
        1000,
        OpType::Set,
        $device['userId'],
    );

    (new OpLogReplayer($db, [COL_ALLOW_DEVICE => $device['pkHex']]))
        ->replay([$entry], $device['userId']);

    expect($db->connection()->table('accounts')->where('id', $accountId)->value('name'))->toBe('Renamed');
    expect($db->connection()->table('op_log_quarantine')->where('reason', 'unknown_column')->count())->toBe(0);
});
