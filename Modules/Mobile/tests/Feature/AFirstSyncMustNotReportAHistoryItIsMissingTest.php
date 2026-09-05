<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen;
use Modules\Mobile\Internal\Http\Livewire\SyncCompleteScreen;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Mobile\Internal\Sync\SyncPhase;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Pairing\DeviceIntroductionService;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\WithheldHistoryReport;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// Two installs, one schema: every query on this path is user-scoped, so a
// second user row is a second device's database. The desktop holds a phone it
// replaced; the new phone pairs with the desktop alone and can therefore verify
// nobody else, which is the ordinary shape of the first sync a household runs.

function firstSyncUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'first-sync-'.$suffix,
        'password' => bcrypt('first-sync-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function firstSyncDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf = false): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Name of '.$deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(random_bytes(32)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-08-01 00:00:00',
        'confirmed_at' => '2026-08-01 00:00:00',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);
}

function firstSyncOps(DatabaseManager $db, int $userId, string $author, int $count, int $startHlcL): void
{
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'user_id' => $userId,
            'device_id' => $author,
            'table_name' => 'merchants',
            'pk' => (string) ($startHlcL + $i),
            'field' => 'name',
            'op_type' => OpType::Set->value,
            'value' => json_encode($author.'-'.$i, JSON_THROW_ON_ERROR),
            'hlc_l' => $startHlcL + $i,
            'hlc_c' => 0,
            'signature' => str_repeat('a', 128),
            'recorded_at' => '2026-08-14 10:00:00',
        ];
    }

    $db->connection()->table('op_log_entries')->insert($rows);
}

// The phone's own identity, generated the way the setup flow generates it, so
// the device id the puller resolves against is the one the request carries.
function firstSyncPhoneIdentity(User $user, Session $session): string
{
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/identity/'.$user->id.'.enc'));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);

    return $identityService->generateAndPersist((int) $user->id, $session)->deviceId;
}

it('reaches a first sync that arrives short, and says so instead of reporting a whole history', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Session $session */
    $session = app(Session::class);

    $phone = firstSyncUser('phone-'.bin2hex(random_bytes(4)));
    $phoneUserId = (int) $phone->id;
    $phoneDeviceId = firstSyncPhoneIdentity($phone, $session);

    firstSyncDevice($db, $phoneUserId, 'the-mac');

    $desktopUserId = (int) firstSyncUser('mac-'.bin2hex(random_bytes(4)))->id;
    firstSyncDevice($db, $desktopUserId, 'the-mac', isSelf: true);
    firstSyncDevice($db, $desktopUserId, $phoneDeviceId);
    firstSyncDevice($db, $desktopUserId, 'old-phone');

    firstSyncOps($db, $desktopUserId, 'the-mac', 100, 1);
    firstSyncOps($db, $desktopUserId, 'old-phone', 155, 1_000);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    // The request a phone that has paired with exactly one device sends, and
    // the answer the desktop owes it. Neither is hand-written: this is the
    // whole reachability claim, and a fixture-shaped response would prove a
    // state the product might not have.
    $request = $exchanger->buildRequest($phoneUserId, $phoneDeviceId, 'the-mac');
    [, $response] = $exchanger->answer($desktopUserId, $request, $phoneDeviceId);

    $verifiable = $request['verifiable'] ?? [];
    sort($verifiable);

    expect($verifiable)->toBe([$phoneDeviceId, 'the-mac'])
        ->and($response['withheld'])->toBe([['device_id' => 'old-phone', 'count' => 155]])
        ->and($response['introductions'])->toHaveCount(1);

    $exchanger->recordIntroductions($phoneUserId, $response, 'the-mac');

    // What the exchange actually delivered, standing in for the frames the
    // transport would have applied on this side.
    firstSyncOps($db, $phoneUserId, 'the-mac', 100, 1);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist($phoneUserId, $session);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    $puller->pull($phoneUserId, $session);
    $progress = $puller->pull($phoneUserId, $session);

    expect($progress['phase'])->toBe(SyncPhase::Complete)
        ->and($progress['records_applied'])->toBe(100)
        ->and($progress['withheld'])->toBe(155)
        // The denominator is what arrived plus what was declared held, so the
        // one number the reader reads is not a claim the exchange never made.
        ->and($progress['records_expected'])->toBe(255)
        ->and($progress['percent'])->toBe(39);

    // Persisted, not only returned: a cold-started process paints the resumed
    // percent from this row and nothing else.
    $row = $db->connection()->table('mobile_sync_progress')->where('user_id', $phoneUserId)->first();

    expect((int) $row->records_expected)->toBe(255);

    Livewire::actingAs($phone)->test(SetupProgressScreen::class)
        ->assertSet('percent', 39);

    Livewire::actingAs($phone)->test(SyncCompleteScreen::class)
        ->assertSet('withheldEntries', 155)
        ->assertSee(Lang::choice('mobile::sync_complete.withheld', 155))
        ->assertSee(Lang::get('mobile::sync_complete.withheld_action', [
            'peer' => 'Name of the-mac',
            'section' => Lang::get('sync::devices.heading'),
        ]));
});

it('stops reporting a hold the reader has confirmed away', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Session $session */
    $session = app(Session::class);

    $phone = firstSyncUser('confirms-'.bin2hex(random_bytes(4)));
    $phoneUserId = (int) $phone->id;
    firstSyncPhoneIdentity($phone, $session);
    firstSyncDevice($db, $phoneUserId, 'the-mac');

    $relayedKey = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger)->recordIntroductions($phoneUserId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [[
            'device_id' => 'old-phone',
            'name' => 'Old phone',
            'ed25519_public_key_hex' => $relayedKey,
        ]],
    ], 'the-mac');

    /** @var WithheldHistoryReport $report */
    $report = app(WithheldHistoryReport::class);

    expect($report->totalFor($phoneUserId))->toBe(155);

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);
    $introductions->confirm($phoneUserId, (int) $introductions->forUser($phoneUserId)[0]->id);

    // The ledger row is untouched — it is the last exchange's report, and the
    // next one has not run. What changed is that this device can now read what
    // it names, so nothing is being held from it any more.
    expect($db->connection()->table('sync_withheld_history')->where('user_id', $phoneUserId)->count())->toBe(1)
        ->and($report->totalFor($phoneUserId))->toBe(0)
        ->and($report->isHolding($phoneUserId))->toBeFalse();
});

it('counts one author held by two peers once, not twice', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $phone = firstSyncUser('two-peers-'.bin2hex(random_bytes(4)));
    $phoneUserId = (int) $phone->id;

    firstSyncDevice($db, $phoneUserId, 'the-phone', isSelf: true);
    firstSyncDevice($db, $phoneUserId, 'the-mac');
    firstSyncDevice($db, $phoneUserId, 'the-laptop');

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    $exchanger->recordIntroductions($phoneUserId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 155]],
        'introductions' => [],
    ], 'the-mac');

    $exchanger->recordIntroductions($phoneUserId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => 12]],
        'introductions' => [],
    ], 'the-laptop');

    /** @var WithheldHistoryReport $report */
    $report = app(WithheldHistoryReport::class);

    expect($report->stillHeldFor($phoneUserId))->toHaveCount(2)
        ->and($report->totalFor($phoneUserId))->toBe(155);
});

it('reports a hold no confirmation can ever end, and promises nothing about it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Session $session */
    $session = app(Session::class);

    $phone = firstSyncUser('unvouchable-'.bin2hex(random_bytes(4)));
    $phoneUserId = (int) $phone->id;
    $phoneDeviceId = firstSyncPhoneIdentity($phone, $session);
    firstSyncDevice($db, $phoneUserId, 'the-mac');

    // The desktop reaches old-phone through a confirmed introduction and no
    // pairing, so it may carry that history and may not vouch for the identity
    // behind it. A vouch made on the strength of a vouch is a chain.
    $desktop = firstSyncUser('voucher-'.bin2hex(random_bytes(4)));
    $desktopUserId = (int) $desktop->id;
    firstSyncDevice($db, $desktopUserId, 'the-mac', isSelf: true);
    firstSyncDevice($db, $desktopUserId, $phoneDeviceId);

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);
    $introductions->record($desktopUserId, 'the-mac', [[
        'device_id' => 'old-phone',
        'name' => 'Old phone',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
    ]]);
    $introductions->confirm($desktopUserId, (int) $introductions->forUser($desktopUserId)[0]->id);

    firstSyncOps($db, $desktopUserId, 'old-phone', 155, 1_000);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);
    $request = $exchanger->buildRequest($phoneUserId, $phoneDeviceId, 'the-mac');
    [, $response] = $exchanger->answer($desktopUserId, $request, $phoneDeviceId);

    // Counted, and named by nobody. This is the shape a screen must not offer
    // an act for: there is no identity here to confirm, and there never will
    // be from this peer.
    expect($response['withheld'])->toBe([['device_id' => 'old-phone', 'count' => 155]])
        ->and($response['introductions'])->toBe([]);

    $exchanger->recordIntroductions($phoneUserId, $response, 'the-mac');

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist($phoneUserId, $session);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);
    $puller->pull($phoneUserId, $session);
    $progress = $puller->pull($phoneUserId, $session);

    expect($progress['phase'])->toBe(SyncPhase::Complete)
        ->and($progress['withheld'])->toBe(155)
        ->and($progress['records_applied'])->toBe(0)
        ->and($progress['percent'])->toBe(0);

    // The line names a condition — an identity being passed on — rather than
    // an act the reader can take. Nothing on this path can ever offer one, and
    // copy that said "confirm that device" would send them looking for a
    // button that is not there.
    $line = Lang::get('mobile::sync_complete.withheld_action', ['peer' => 'x', 'section' => 'y']);

    expect($line)->toContain('once one of your devices passes on that identity');

    Livewire::actingAs($phone)->test(SyncCompleteScreen::class)
        ->assertSet('withheldEntries', 155)
        ->assertSee(Lang::choice('mobile::sync_complete.withheld', 155));
});
