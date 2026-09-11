<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Community\Public\Services\CommunitySettings;
use Modules\Sync\Internal\Merge\Strategies\JsonKeyUnionStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Two independent opt-outs share one JSON column, and absence is not false: a
// key nobody has written reads as the enum's default, which is on for both.
function poobUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'poob-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function poobWriter(int $userId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'poob-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

function poobPublished(DatabaseManager $db, int $userId): mixed
{
    return json_decode((string) $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'users')
        ->where('field', 'community_settings')
        ->orderByDesc('id')
        ->value('value'), true);
}

function poobEntry(?string $value, int $hlcL, string $deviceId): OpLogEntry
{
    return new OpLogEntry(
        table: 'users',
        pk: 1,
        field: 'community_settings',
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $deviceId,
        opType: OpType::Set,
        signature: str_repeat('0', 128),
        userId: 1,
    );
}

it('keeps an opt-out each device made', function (): void {
    $resolved = (new JsonKeyUnionStrategy)->resolve([
        poobEntry('{"useSharedList":false}', 100, 'desktop'),
        poobEntry('{"offerToContribute":false}', 200, 'phone'),
    ]);

    expect($resolved)->toBe(['useSharedList' => false, 'offerToContribute' => false]);
});

// The half a registry line alone does not fix. WriteUserPreference reads the
// column back through the query builder, so a JSON column is handed over as the
// stored TEXT -- which the strategy refuses, quarantining the op.
it('publishes the map as a map, not as the stored text', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = poobUser($db);

    poobWriter($userId)->writeSet('users', $userId, 'community_settings', '{"useSharedList":false}');

    expect(poobPublished($db, $userId))->toBe(['useSharedList' => false]);
});

// A value that is not an object is published exactly as it came, so the
// strategy's own refusal is what reports it rather than the writer inventing a
// shape for it.
it('leaves a value that is not a map alone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = poobUser($db);

    poobWriter($userId)->writeSet('users', $userId, 'community_settings', 'not json');

    expect(poobPublished($db, $userId))->toBe('not json');
});

// The positive control: a column that is NOT a key union keeps travelling as
// whatever its writer handed over, so the branch above cannot be decoding
// every string on the wire.
it('does not decode a string on a column that merges whole', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = poobUser($db);

    poobWriter($userId)->writeSet('users', $userId, 'timezone', '{"tz":"Europe/Amsterdam"}');

    $published = json_decode((string) $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('field', 'timezone')
        ->orderByDesc('id')
        ->value('value'), true);

    expect($published)->toBe('{"tz":"Europe/Amsterdam"}');
});

it('reads a merged pair of opt-outs as two opt-outs', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = poobUser($db);

    $merged = (new JsonKeyUnionStrategy)->resolve([
        poobEntry('{"useSharedList":false}', 100, 'desktop'),
        poobEntry('{"offerToContribute":false}', 200, 'phone'),
    ]);

    $db->connection()->table('users')->where('id', $userId)
        ->update(['community_settings' => json_encode($merged)]);

    /** @var CommunitySettings $settings */
    $settings = app(CommunitySettings::class);

    expect($settings->enabled(CommunitySetting::UseSharedList, $userId))->toBeFalse()
        ->and($settings->enabled(CommunitySetting::OfferToContribute, $userId))->toBeFalse();
});
