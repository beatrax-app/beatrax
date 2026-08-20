<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

/*
 * Covers the gap that left a freshly paired device on "0 of 0 records":
 * capture is event-driven, so rows that predate sync were never in the log
 * and a peer asking for everything received nothing.
 */

function backfillFixtureUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'backfill-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function backfillWriter(int $userId, string $deviceId = 'backfill-device'): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    // Register the writer the way production does. The self-row is confirmed
    // the moment an identity is generated, and coverage is only counted for
    // authors a peer can still verify — an unregistered writer would leave
    // every op it wrote uncovered, which is the defect these tests sit on top
    // of rather than the state they mean to set up.
    backfillRegisterDevice($userId, $deviceId, sodium_crypto_sign_publickey($keypair));

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

function backfillRegisterDevice(int $userId, string $deviceId, string $publicKey): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Fixture device',
        'ed25519_public_key_hex' => sodium_bin2hex($publicKey),
        'x25519_public_key_hex' => str_repeat('00', 32),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => '2026-06-14T00:00:00+00:00',
        'confirmed_at' => '2026-06-14T00:00:00+00:00',
        'last_seen_at' => null,
        'created_at' => '2026-06-14T00:00:00+00:00',
        'updated_at' => '2026-06-14T00:00:00+00:00',
    ]);
}

function backfillSeedAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Pre-sync account',
        'slug' => 'pre-sync-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);
}

it('captures rows that existed before sync was enabled', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = backfillFixtureUser($db);
    $accountId = backfillSeedAccount($db, $userId);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $captured = $backfiller->backfill($userId, backfillWriter($userId));

    expect($captured)->toBeGreaterThan(0);

    $entries = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'accounts')
        ->where('pk', (string) $accountId)
        ->get();

    expect($entries)->not->toBeEmpty()
        ->and($entries->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($entries->pluck('field')->all())->toContain('name');
});

it('captures nothing on a second run, every row already carrying a create op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = backfillFixtureUser($db);
    backfillSeedAccount($db, $userId);

    $writer = backfillWriter($userId);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->backfill($userId, $writer);

    $afterFirst = $db->connection()->table('op_log_entries')->where('user_id', $userId)->count();

    expect($backfiller->backfill($userId, $writer))->toBe(0)
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())
        ->toBe($afterFirst);
});

it('never captures another user rows', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $mine = backfillFixtureUser($db);
    $theirs = backfillFixtureUser($db);
    $theirAccount = backfillSeedAccount($db, $theirs);
    backfillSeedAccount($db, $mine);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->backfill($mine, backfillWriter($mine));

    expect(
        $db->connection()->table('op_log_entries')
            ->where('table_name', 'accounts')
            ->where('pk', (string) $theirAccount)
            ->exists()
    )->toBeFalse();
});

it('still captures pre-sync rows when an import already wrote ops for other rows', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = backfillFixtureUser($db);

    // The account that predates sync — the one that must reach the new peer.
    $preSyncAccount = backfillSeedAccount($db, $userId);

    $writer = backfillWriter($userId);

    // An import runs between switching sync on and pairing, capturing its own
    // rows. That is the only op-log history this user has.
    $importedAccount = backfillSeedAccount($db, $userId);
    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->captureRowsById('accounts', [$importedAccount], $userId, $writer);

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->exists())->toBeTrue();

    // Pairing confirm. The whole backfill used to be skipped here because the
    // user "had history", and the pre-sync account never left the desktop.
    $captured = $backfiller->backfill($userId, $writer);

    expect($captured)->toBeGreaterThan(0);

    $entries = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'accounts')
        ->where('pk', (string) $preSyncAccount)
        ->get();

    expect($entries)->not->toBeEmpty()
        ->and($entries->pluck('field')->all())->toContain('name');
});

it('does not write a second create op for a row the import already captured', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = backfillFixtureUser($db);
    $accountId = backfillSeedAccount($db, $userId);

    $writer = backfillWriter($userId);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->captureRowsById('accounts', [$accountId], $userId, $writer);

    $afterImport = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'accounts')
        ->where('pk', (string) $accountId)
        ->count();

    $backfiller->backfill($userId, $writer);

    // Replaying two create ops for one pk is what the all-or-nothing guard
    // was protecting against; the row-wise skip has to keep that promise.
    expect($db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'accounts')
        ->where('pk', (string) $accountId)
        ->count())->toBe($afterImport);
});

it('re-captures a row whose only create op was signed by an identity the registry lost', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = backfillFixtureUser($db);
    $accountId = backfillSeedAccount($db, $userId);

    // Switching sync off and on again used to mint a fresh identity, leaving
    // the whole op log signed by a device the registry no longer holds. Every
    // peer drops those entries as missing_device_key, so the desktop shipped
    // thousands of ops and the phone applied none — while the backfill
    // considered each row captured and refused to re-emit it.
    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $captured = $backfiller->backfill($userId, backfillWriter($userId, 'retired-identity'));
    expect($captured)->toBeGreaterThan(0);

    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', 'retired-identity')
        ->delete();

    $recaptured = $backfiller->backfill($userId, backfillWriter($userId, 'current-identity'));

    expect($recaptured)->toBeGreaterThan(0);

    $verifiable = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'accounts')
        ->where('pk', (string) $accountId)
        ->where('device_id', 'current-identity')
        ->count();

    expect($verifiable)->toBeGreaterThan(0, 'the row is still unreachable by any peer');
});

it('leaves a row alone when its create op author is still a confirmed device', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = backfillFixtureUser($db);
    backfillSeedAccount($db, $userId);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->backfill($userId, backfillWriter($userId, 'still-here'));

    expect($backfiller->backfill($userId, backfillWriter($userId, 'second-writer')))->toBe(0);
});
