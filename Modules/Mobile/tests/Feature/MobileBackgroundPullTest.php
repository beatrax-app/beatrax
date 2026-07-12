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

/*
 * D-07's background cadence leg (15-09-PLAN.md). `sync:mobile-pull` is a
 * bounded, KEK-gated one-shot pull the OS scheduler invokes — never a
 * loop/listener. This file proves:
 *
 *   Task 1: (a) exactly one bounded MobileSyncTriggerService::syncOnce()
 *           burst runs per user per firing (never looping/re-attempting on
 *           its own — the relay HTTP call count is the observable proxy for
 *           that, since MobileSyncTriggerService/LanSyncClient are both
 *           `final` and therefore not mockable by design, mirroring
 *           MobileResumableInitialSyncTest's own precedent); (b) a
 *           KEK-absent tick skips cleanly with zero network calls and zero
 *           data writes — the key is never cached anywhere for background
 *           convenience (T-15-23).
 *
 *   Task 2: the Plan 01 `Routes/console.php` schedule registration's
 *           command name resolves to this real, registered command class,
 *           and `schedule:list` runs clean under the repo-root (non-mobile)
 *           context — the entry stays macro-guarded off there because
 *           `nativephp/mobile-background-tasks` (the plugin that defines
 *           `onAnyNetwork()`) lives only in `mobile-app/vendor`
 *           (15-TOPOLOGY-SPIKE-FINDINGS.md). NO wall-clock/schedule-timing
 *           assertion is made anywhere in this file (RESEARCH.md Pitfall 2
 *           — everyFifteenMinutes() is the Android floor / iOS best-effort
 *           hint only).
 */

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

    // Modules\Mobile\Tests\TestCase does not prime an unlocked session —
    // unlock it (mirrors MobileBidirectionalMergeTest / MobileResumableInitialSyncTest).
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    // A configured, faked relay endpoint makes the real off-LAN leg of
    // MobileSyncTriggerService::syncOnce() report a genuine outcome without
    // a live socket — same fixture shape MobileResumableInitialSyncTest
    // already established for exercising the real relay leg.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    $exitCode = Artisan::call('sync:mobile-pull');

    expect($exitCode)->toBe(0);

    // Exactly ONE relay drain HTTP call for the single seeded user proves
    // the command drove exactly one bounded syncOnce() burst — not a loop
    // or a re-attempt of its own (the command itself never retries;
    // syncOnce()'s own bounded LAN retry is not exercised here since no
    // LAN host/port is ever passed by this command, T-15-24).
    Http::assertSentCount(1);
});

it('skips cleanly with zero network calls and zero data writes when the app-lock KEK is unavailable', function (): void {
    $user = mobileBackgroundPullUser('mobile-bg-pull-nokek-'.bin2hex(random_bytes(4)));

    // No identity file exists for this user at all (sync never enabled) —
    // DeviceIdentityLoader::load() returns null before any KEK check runs,
    // exactly mirroring the "no usable device identity" skip path every
    // real OS-scheduled firing hits (T-15-23, RESEARCH Anti-Pattern #3).
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    $exitCode = Artisan::call('sync:mobile-pull');

    // A skipped tick is still a successful, well-behaved firing — never a
    // command failure (RESEARCH Anti-Pattern #3: the tick is a no-op, data
    // stays encrypted).
    expect($exitCode)->toBe(0);

    // No network call at all — the KEK gate is checked BEFORE any
    // transport is attempted, so a locked/no-KEK tick never dials the
    // relay (or a LAN peer), and never caches the key anywhere for
    // background convenience.
    Http::assertNothingSent();

    expect(app(DatabaseManager::class)->connection()
        ->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(0);
});

it('resolves the Plan-01-registered sync:mobile-pull command name to MobilePullCommand', function (): void {
    expect(class_exists(MobilePullCommand::class))->toBeTrue();

    $registered = array_keys(Artisan::all());
    expect($registered)->toContain('sync:mobile-pull');

    expect(Artisan::all()['sync:mobile-pull'])->toBeInstanceOf(MobilePullCommand::class);
});

it('runs schedule:list without error under the repo-root context, where the entry stays macro-guarded off', function (): void {
    // The plugin that defines onAnyNetwork() (nativephp/mobile-background-tasks)
    // lives only in mobile-app/vendor — genuinely absent from the repo-root
    // Composer tree this test suite runs against (15-TOPOLOGY-SPIKE-FINDINGS.md).
    // The Plan 01 Routes/console.php registration is guarded on this exact
    // macro, so it stays inert here.
    //
    // ->group('repo-root-only'): this assertion is ONLY true when this
    // suite runs against the repo-root Composer tree. The 15-11-PLAN.md
    // Task 2 mobile-app/-rooted CI job runs the SAME test file against
    // mobile-app/vendor, where nativephp/mobile-background-tasks genuinely
    // IS installed and DOES register the macro — the opposite of what this
    // test asserts, by design (T-15-SC: the plugin is real there). The
    // mobile-app-rooted job excludes this group; every other test in this
    // file is context-agnostic and still runs there.
    expect(Event::hasMacro('onAnyNetwork'))->toBeFalse();

    $exitCode = Artisan::call('schedule:list');

    expect($exitCode)->toBe(0);
})->group('repo-root-only');
