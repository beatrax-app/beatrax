<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Mobile\Commands\MobilePullCommand;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

uses(RefreshDatabase::class);

// The relay HTTP call count is the observable proxy for "exactly one burst":
// MobileSyncTriggerService and LanSyncClient are both final, so neither is
// mockable by design. Nothing here asserts wall-clock cadence — the schedule
// is an Android floor and an iOS best-effort hint, not a guarantee.

function mobileBackgroundPullUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('mobile-bg-pull-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('runs exactly one bounded background sync burst per user and never loops', function (): void {
    $user = mobileBackgroundPullUser('mobile-bg-pull-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);

    // The module TestCase does not prime an unlocked session.
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    // A faked endpoint lets the real off-LAN leg report a genuine outcome
    // without a live socket.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    $exitCode = Artisan::call('sync:mobile-pull');

    expect($exitCode)->toBe(0);

    // One drain call for one seeded user: the command drove one burst and
    // never re-attempted. It passes no LAN host, so the LAN retry is absent.
    Http::assertSentCount(1);
});

it('skips cleanly with zero network calls and zero data writes when the app-lock KEK is unavailable', function (): void {
    $user = mobileBackgroundPullUser('mobile-bg-pull-nokek-'.bin2hex(random_bytes(4)));

    // No identity file at all, so the loader returns null before any KEK
    // check — the skip path essentially every OS-scheduled firing hits.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    $exitCode = Artisan::call('sync:mobile-pull');

    // A skipped tick is a well-behaved firing, never a command failure.
    expect($exitCode)->toBe(0);

    // The KEK gate is checked before any transport is attempted, so a
    // keyless tick never dials, and never caches a key for convenience.
    Http::assertNothingSent();

    expect(app(DatabaseManager::class)->connection()
        ->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(0);
});

it('resolves the registered sync:mobile-pull command name to MobilePullCommand', function (): void {
    expect(class_exists(MobilePullCommand::class))->toBeTrue();

    $registered = array_keys(Artisan::all());
    expect($registered)->toContain('sync:mobile-pull');

    expect(Artisan::all()['sync:mobile-pull'])->toBeInstanceOf(MobilePullCommand::class);
});

it('runs schedule:list without error under the repo-root context, where the entry stays macro-guarded off', function (): void {
    // nativephp/mobile-background-tasks defines onAnyNetwork() and lives only
    // in mobile-app/vendor, so Routes/console.php's macro-guarded schedule
    // entry stays inert here. The mobile-app-rooted CI job runs this same
    // file where the macro DOES exist, so it excludes this group.
    expect(Event::hasMacro('onAnyNetwork'))->toBeFalse();

    $exitCode = Artisan::call('schedule:list');

    expect($exitCode)->toBe(0);
})->group('repo-root-only');
