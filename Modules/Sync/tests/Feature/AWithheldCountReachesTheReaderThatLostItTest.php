<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\IntroducedDevicesSection;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\WithheldLedger;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// A peer withholds for four reasons and can vouch for only one of them. The
// count used to be written beside the offered identity and nowhere else, so
// three of the four arrived on the wire and were dropped by the device that had
// just been told it was missing history.

function withheldUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'withheld-'.$suffix,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function withheldDevice(DatabaseManager $db, int $userId, string $deviceId, string $name, bool $isSelf): string
{
    $keyHex = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $name,
        'ed25519_public_key_hex' => $keyHex,
        'x25519_public_key_hex' => sodium_bin2hex(random_bytes(32)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-09-05T10:00:00Z',
        'confirmed_at' => '2026-09-05T10:00:00Z',
        'created_at' => '2026-09-05T10:00:00Z',
        'updated_at' => '2026-09-05T10:00:00Z',
    ]);

    return $keyHex;
}

function withheldExchanger(DatabaseManager $db): PeerCatchUpExchanger
{
    return new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
}

it('records what a peer is holding back even when nobody can vouch for its author', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) withheldUser('unvouched')->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);

    // The shape a peer sends when the author it withheld is one IT removed:
    // the count is real, and there is no identity it may honestly relay.
    $stored = withheldExchanger($db)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [],
    ], 'the-mac');

    expect($stored)->toBe(0)
        ->and($db->connection()->table('device_introductions')->count())->toBe(0)
        ->and(new WithheldLedger($db)->forUser($userId))
        ->toBe([['peer_device_id' => 'the-mac', 'author_device_id' => 'old-phone', 'entry_count' => 155]]);
});

it('replaces the whole report rather than leaving a number the peer no longer stands behind', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) withheldUser('stale')->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);

    $exchanger = withheldExchanger($db);

    $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155], ['device_id' => 'lost-tablet', 'count' => 9]],
        'introductions' => [],
    ], 'the-mac');

    expect(new WithheldLedger($db)->forUser($userId))->toHaveCount(2);

    // The reader confirmed an introduction for old-phone between the two
    // exchanges, so the peer withholds nothing for it any more.
    $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'lost-tablet', 'count' => 9]],
        'introductions' => [],
    ], 'the-mac');

    expect(new WithheldLedger($db)->forUser($userId))
        ->toBe([['peer_device_id' => 'the-mac', 'author_device_id' => 'lost-tablet', 'entry_count' => 9]]);

    $exchanger->recordIntroductions($userId, ['withheld' => [], 'introductions' => []], 'the-mac');

    expect(new WithheldLedger($db)->forUser($userId))->toBe([]);
});

it('keeps one peer report out of another peer report', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) withheldUser('two-peers')->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);
    withheldDevice($db, $userId, 'the-laptop', 'The laptop', isSelf: false);

    $exchanger = withheldExchanger($db);

    $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [],
    ], 'the-mac');

    $exchanger->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 12]],
        'introductions' => [],
    ], 'the-laptop');

    expect(new WithheldLedger($db)->forUser($userId))->toBe([
        ['peer_device_id' => 'the-laptop', 'author_device_id' => 'old-phone', 'entry_count' => 12],
        ['peer_device_id' => 'the-mac', 'author_device_id' => 'old-phone', 'entry_count' => 155],
    ]);
});

it('puts the number and the author on the device list, with the peer that is holding them', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = withheldUser('screen');
    $userId = (int) $user->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);

    withheldExchanger($db)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [],
    ], 'the-mac');

    $this->actingAs($user);

    Livewire::test(IntroducedDevicesSection::class)
        ->assertSet('introductions', [])
        ->assertSet('withheld', [[
            'author' => 'old-phone',
            'peer' => 'The Mac',
            'count' => 155,
        ]])
        ->assertSee(Lang::choice('sync::devices.withheld_count', 155, ['name' => 'old-phone']))
        ->assertSee(Lang::get('sync::devices.withheld_by', ['name' => 'The Mac']))
        ->assertSee(Lang::get('sync::devices.withheld_heading'));
});

it('reads the introduction count off the same report rather than a copy of it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = withheldUser('introduced');
    $userId = (int) $user->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);

    $relayedKey = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    withheldExchanger($db)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [[
            'device_id' => 'old-phone',
            'name' => 'Old phone',
            'ed25519_public_key_hex' => $relayedKey,
        ]],
    ], 'the-mac');

    $this->actingAs($user);

    $component = Livewire::test(IntroducedDevicesSection::class);

    // An author with an identity beside it is NOT repeated in the list below:
    // one withholding is one row, and the row that can end it is the one with
    // the button on it.
    expect($component->get('withheld'))->toBe([])
        ->and($component->get('introductions')[0]['withheld'])->toBe(155)
        ->and($component->get('introductions')[0]['introduced_by'])->toBe('The Mac')
        ->and($component->get('introductions')[0]['confirmed'])->toBeFalse();

    $component->call('confirmIntroduction', $component->get('introductions')[0]['id']);

    expect($component->get('introductions')[0]['confirmed'])->toBeTrue();
});

it('says nothing about a withholding a peer that cannot name itself reported', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) withheldUser('nameless-peer')->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);

    // A session that never authenticated has no peer id to file the report
    // under, and a row keyed to nobody could never be cleared by the peer that
    // wrote it — it would sit on the screen for the life of the install.
    withheldExchanger($db)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [],
    ], '');

    expect(new WithheldLedger($db)->forUser($userId))->toBe([]);
});

it('shows no count on an introduction the vouching device reported none for', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = withheldUser('no-count');
    $userId = (int) $user->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);

    $relayedKey = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    withheldExchanger($db)->recordIntroductions($userId, [
        'withheld' => [],
        'introductions' => [[
            'device_id' => 'old-phone',
            'name' => 'Old phone',
            'ed25519_public_key_hex' => $relayedKey,
        ]],
    ], 'the-mac');

    $this->actingAs($user);

    $component = Livewire::test(IntroducedDevicesSection::class);

    expect($component->get('introductions')[0]['withheld'])->toBe(0)
        ->and($component->get('withheld'))->toBe([]);

    $component->call('dismissIntroduction', $component->get('introductions')[0]['id']);

    expect($component->get('introductions'))->toBe([])
        ->and($db->connection()->table('device_introductions')->count())->toBe(0);
});

it('drops an introduction from the list once the device it names is in the registry', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = withheldUser('shadowed');
    $userId = (int) $user->id;

    withheldDevice($db, $userId, 'new-phone', 'New phone', isSelf: true);
    withheldDevice($db, $userId, 'the-mac', 'The Mac', isSelf: false);

    $relayedKey = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    withheldExchanger($db)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [[
            'device_id' => 'old-phone',
            'name' => 'Old phone',
            'ed25519_public_key_hex' => $relayedKey,
        ]],
    ], 'the-mac');

    $this->actingAs($user);

    expect(Livewire::test(IntroducedDevicesSection::class)->get('introductions'))->toHaveCount(1);

    // The device pairs afterwards, which is the one order that leaves both rows
    // standing. The pairing answers for it from here on, so the weaker grant
    // stops being offered rather than sitting beside the device list saying the
    // same thing in different words.
    withheldDevice($db, $userId, 'old-phone', 'Old phone', isSelf: false);

    $component = Livewire::test(IntroducedDevicesSection::class);

    expect($component->get('introductions'))->toBe([])
        ->and($component->get('withheld'))->toBe([]);
});
