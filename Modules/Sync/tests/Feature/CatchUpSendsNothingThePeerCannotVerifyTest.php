<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Transport\CatchUpDelta;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\WithheldLedger;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// The filter and the introduction are one exchange: the authors a device says
// it cannot verify are exactly the authors the answer withholds, and exactly
// the ones it offers to introduce. Measured on the pair that produced it — the
// Mac held 155 entries signed by a phone the replacement had never met.

function filterUser(DatabaseManager $db, string $suffix): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'catchup-filter-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return string The device's Ed25519 public key, hex.
 */
function filterDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf = false, bool $confirmed = true): string
{
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Name of '.$deviceId,
        'ed25519_public_key_hex' => $publicHex,
        'x25519_public_key_hex' => sodium_bin2hex(random_bytes(32)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => $confirmed ? '2026-06-01 00:00:00' : null,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $publicHex;
}

function filterOps(DatabaseManager $db, int $userId, string $author, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $db->connection()->table('op_log_entries')->insert([
            'user_id' => $userId,
            'device_id' => $author,
            'table_name' => 'merchants',
            'pk' => (string) ($i + 1),
            'field' => 'name',
            'op_type' => OpType::Set->value,
            'value' => json_encode($author.'-'.$i, JSON_THROW_ON_ERROR),
            'hlc_l' => 1_000 + $i,
            'hlc_c' => 0,
            'signature' => str_repeat('a', 128),
            'recorded_at' => '2026-06-14 10:00:00',
        ]);
    }
}

/**
 * @return list<string>
 */
function filterAuthorsIn(CatchUpDelta $delta): array
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

it('sends no op whose author the asking device did not say it can verify', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'withholds');

    filterDevice($db, $userId, 'the-mac', isSelf: true);
    filterDevice($db, $userId, 'new-phone');
    filterDevice($db, $userId, 'old-phone');

    filterOps($db, $userId, 'the-mac', 3);
    filterOps($db, $userId, 'old-phone', 4);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    [$delta, $control] = $exchanger->answer($userId, [
        'cursors' => [],
        'verifiable' => ['the-mac', 'new-phone'],
    ], 'new-phone');

    $authors = filterAuthorsIn($delta);

    expect(array_count_values($authors))->toBe(['the-mac' => 3])
        ->and($control['withheld'])->toBe([['device_id' => 'old-phone', 'count' => 4]]);
});

it('sends everything to a peer that named no authors at all', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'older-peer');

    filterDevice($db, $userId, 'the-mac', isSelf: true);
    filterDevice($db, $userId, 'old-phone');

    filterOps($db, $userId, 'the-mac', 2);
    filterOps($db, $userId, 'old-phone', 5);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    [$delta, $control] = $exchanger->answer($userId, ['cursors' => []], 'peer-on-an-older-build');

    $authors = filterAuthorsIn($delta);
    sort($authors);

    expect(array_count_values($authors))->toBe(['old-phone' => 5, 'the-mac' => 2])
        ->and($control['withheld'])->toBe([])
        ->and($control['introductions'])->toBe([]);
});

it('offers the identity of a confirmed device it withheld, and never one it only retains', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'offers');

    filterDevice($db, $userId, 'the-mac', isSelf: true);
    $oldPhoneKey = filterDevice($db, $userId, 'old-phone');
    filterDevice($db, $userId, 'device-this-mac-removed', confirmed: false);

    filterOps($db, $userId, 'old-phone', 4);
    filterOps($db, $userId, 'device-this-mac-removed', 2);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    [, $control] = $exchanger->answer($userId, [
        'cursors' => [],
        'verifiable' => ['the-mac'],
    ], 'new-phone');

    expect($control['withheld'])->toBe([
        ['device_id' => 'device-this-mac-removed', 'count' => 2],
        ['device_id' => 'old-phone', 'count' => 4],
    ])
        ->and($control['introductions'])->toBe([[
            'device_id' => 'old-phone',
            'name' => 'Name of old-phone',
            'ed25519_public_key_hex' => $oldPhoneKey,
        ]]);
});

it('asks for exactly the authors it can verify, and no more', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'asks');

    filterDevice($db, $userId, 'new-phone', isSelf: true);
    filterDevice($db, $userId, 'the-mac');
    filterDevice($db, $userId, 'a-device-this-phone-removed', confirmed: false);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $request = $exchanger->buildRequest($userId, 'new-phone', 'the-mac');

    $verifiable = $request['verifiable'] ?? [];
    sort($verifiable);

    expect($verifiable)->toBe(['new-phone', 'the-mac']);
});

it('stores a relayed identity unconfirmed, naming the voucher and the count it is holding', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'records');

    $selfKey = filterDevice($db, $userId, 'new-phone', isSelf: true);
    filterDevice($db, $userId, 'the-mac');

    $relayedKey = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $stored = $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [[
            'device_id' => 'old-phone',
            'name' => 'Old phone',
            'ed25519_public_key_hex' => $relayedKey,
        ]],
    ], 'the-mac');

    $row = $db->connection()->table('device_introductions')->where('user_id', $userId)->first();

    $expectedWords = implode(' ', new SafetyNumberDeriver(Bip39WordList::WORDS)->deriveWords($selfKey, $relayedKey));

    expect($stored)->toBe(1)
        ->and($row->device_id)->toBe('old-phone')
        ->and($row->name)->toBe('Old phone')
        ->and($row->ed25519_public_key_hex)->toBe($relayedKey)
        ->and($row->introduced_by_device_id)->toBe('the-mac')
        ->and($row->verification_confirmed_at)->toBeNull()
        ->and(new WithheldLedger($db)->forUser($userId))
        ->toBe([['peer_device_id' => 'the-mac', 'author_device_id' => 'old-phone', 'entry_count' => 155]])
        // Derived here from the key that arrived, never copied from the sender:
        // a fingerprint a reader is asked to trust because it was sent is not
        // one, and this is the assertion that keeps it that way.
        ->and($row->safety_number_words)->toBe($expectedWords);
});

it('refuses a relayed identity for a device this install has already decided about', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'refuses');

    filterDevice($db, $userId, 'new-phone', isSelf: true);
    filterDevice($db, $userId, 'the-mac');
    filterDevice($db, $userId, 'a-device-this-phone-removed', confirmed: false);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $stored = $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'a-device-this-phone-removed', 'count' => 9]],
        'introductions' => [[
            'device_id' => 'a-device-this-phone-removed',
            'name' => 'Removed here',
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        ]],
    ], 'the-mac');

    expect($stored)->toBe(0)
        ->and($db->connection()->table('device_introductions')->where('user_id', $userId)->count())->toBe(0);
});

it('drops a relayed key that is not a well-formed Ed25519 public key', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = filterUser($db, 'malformed');

    filterDevice($db, $userId, 'new-phone', isSelf: true);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $stored = $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 3]],
        'introductions' => [
            ['device_id' => 'old-phone', 'name' => 'Old phone', 'ed25519_public_key_hex' => 'not-a-key'],
            ['device_id' => 'other-phone', 'name' => 'Other', 'ed25519_public_key_hex' => str_repeat('A', 64)],
        ],
    ], 'the-mac');

    expect($stored)->toBe(0)
        ->and($db->connection()->table('device_introductions')->where('user_id', $userId)->count())->toBe(0);
});
