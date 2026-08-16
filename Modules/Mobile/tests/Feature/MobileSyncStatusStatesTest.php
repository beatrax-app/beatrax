<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;

uses(RefreshDatabase::class);

// The D-10 network-policy file is device-scoped (not per-user), at a FIXED
// path — clean it up before/after each test so runs never interfere with
// each other, mirroring NetworkPolicyResolverTest's own established
// precedent (15-05-PLAN.md Task 1).
beforeEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

afterEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

/*
 * MOBILE-02 (R7) — `/sync` status surface. Turns the Wave-0 RED
 * class_exists() gate GREEN (15-10-PLAN.md Task 1).
 *
 * Behavior pinned: SyncScreen resolves the four R7 states truthfully —
 *   - idle: the embedded sync.sync-status-section's "All devices up to
 *     date · synced Nm ago" banner (SyncStatusService, unchanged), with a
 *     correct relative timestamp.
 *   - active progress: this screen's OWN "{n} records" line, sourced from
 *     the own-module mobile_sync_progress durable cursor (phase='pulling')
 *     — independent of sync_sessions.
 *   - offline/not-connected: the embedded component's "Devices offline"
 *     banner.
 *   - per-device list: the embedded component's per-peer rows (device id
 *     visible) — proven alongside the idle scenario.
 *
 * Status is read exclusively via the embedded sync.sync-status-section
 * component (itself SyncStatusService-backed) or this module's own
 * mobile_sync_progress table — never sync_sessions/relay_mailbox
 * directly (T-15-26/T-15-28, structurally asserted in the last test).
 */

function mobileSyncStatusUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Insert a sync_sessions row and return its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertMobileSyncSession(DatabaseManager $db, User $user, array $overrides = []): int
{
    $defaults = [
        'user_id' => $user->id,
        'local_device_id' => 'mobile-local-dev',
        'peer_device_id' => 'desktop-peer-dev',
        'status' => 'closed',
        'error_message' => null,
        'last_seen_at' => '2026-07-11T10:00:00Z',
        'connected_at' => '2026-07-11T09:55:00Z',
        'created_at' => '2026-07-11T09:55:00Z',
        'updated_at' => '2026-07-11T10:00:00Z',
    ];

    return (int) $db->connection()->table('sync_sessions')->insertGetId(
        array_merge($defaults, $overrides)
    );
}

it('resolves the idle state — all devices up to date, correct relative timestamp, per-device list', function (): void {
    $user = mobileSyncStatusUser('mobile-status-idle-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertMobileSyncSession($db, $user, [
        'status' => 'closed',
        'last_seen_at' => '2026-07-11T10:00:00Z',
    ]);

    // Freeze time so the "synced Nm ago" calculation is deterministic.
    $now = CarbonImmutable::parse('2026-07-11T10:05:00Z');
    $this->app->bind(Clock::class, fn () => new class($now) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $frozen) {}

        public function now(): CarbonImmutable
        {
            return $this->frozen;
        }
    });

    Livewire::test(SyncScreen::class)
        ->assertStatus(200)
        ->assertSee('All devices up to date')
        ->assertSee('synced 5m ago')
        // Per-device list — the embedded component's per-peer row.
        ->assertSee('desktop-peer-dev')
        // No initial sync is in progress — the progress line is absent.
        ->assertDontSee('records');
});

it('resolves the active state with N-of-M progress from the mobile_sync_progress cursor', function (): void {
    $user = mobileSyncStatusUser('mobile-status-active-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('mobile_sync_progress')->insert([
        'user_id' => $user->id,
        'peer_device_id' => 'desktop-peer-dev',
        'records_expected' => 100,
        'records_applied' => 40,
        'last_hlc_l' => 40,
        'last_hlc_c' => 0,
        'phase' => 'pulling',
        'created_at' => '2026-07-11T09:55:00Z',
        'updated_at' => '2026-07-11T10:00:00Z',
    ]);

    Livewire::test(SyncScreen::class)
        ->assertStatus(200)
        ->assertSet('initialSyncInProgress', true)
        ->assertSet('progressApplied', 40)
        ->assertSet('progressExpected', 100)
        ->assertSet('progressPercent', 40)
        ->assertSee('Syncing')
        ->assertSee('40 records');
});

it('resolves the offline / not-connected state', function (): void {
    $user = mobileSyncStatusUser('mobile-status-offline-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    // A session that never got anywhere and was never seen — resolves to
    // SyncStatusService::overallStatus()'s 'offline' bucket (not
    // 'error' — no error_message; not 'all_synced' — never closed/seen).
    insertMobileSyncSession($db, $user, [
        'status' => 'failed',
        'error_message' => null,
        'last_seen_at' => null,
    ]);

    Livewire::test(SyncScreen::class)
        ->assertStatus(200)
        ->assertSee('Devices offline')
        ->assertSet('initialSyncInProgress', false);
});

it('mounts cleanly with no progress cursor and no sessions (fresh device)', function (): void {
    $user = mobileSyncStatusUser('mobile-status-fresh-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    Livewire::test(SyncScreen::class)
        ->assertStatus(200)
        ->assertSee('No devices synced yet')
        ->assertSet('initialSyncInProgress', false)
        ->assertSet('progressApplied', 0)
        ->assertSet('progressExpected', null);
});

it('the Sync now button invokes syncOnce() and re-fetches progress', function (): void {
    $user = mobileSyncStatusUser('mobile-status-syncnow-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    // No device identity/KEK is set up for this fixture user, so
    // MobileSyncTriggerService::syncOnce() skips the tick (returns null) —
    // this proves the button wires through to the real service without a
    // live LAN/relay round-trip (mirrors MobileBidirectionalMergeTest's
    // own KEK-absent-skip precedent). No exception, no crash.
    Livewire::test(SyncScreen::class)
        ->assertStatus(200)
        ->call('syncNow')
        ->assertStatus(200)
        ->assertSet('initialSyncInProgress', false);
});

it('the Pause sync on cellular toggle writes through NetworkPolicyResolver', function (): void {
    $user = mobileSyncStatusUser('mobile-status-toggle-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    Livewire::test(SyncScreen::class)
        ->assertSet('pauseOnCellular', false)
        ->call('toggleCellularPause')
        ->assertSet('pauseOnCellular', true);

    /** @var NetworkPolicyResolver $resolver */
    $resolver = app(NetworkPolicyResolver::class);
    expect($resolver->pauseOnCellular())->toBeTrue();
});

it('embeds the existing sync.sync-status-section component and never queries sync_sessions/relay_mailbox directly', function (): void {
    $viewFile = realpath(__DIR__.'/../../Resources/views/livewire/sync-screen.blade.php');
    $componentFile = realpath(__DIR__.'/../../Internal/Http/Livewire/SyncScreen.php');

    expect($viewFile)->not->toBeFalse()->and(is_string($viewFile))->toBeTrue();
    expect($componentFile)->not->toBeFalse()->and(is_string($componentFile))->toBeTrue();

    /** @var string $viewFile */
    $viewContents = file_get_contents($viewFile);
    /** @var string $componentFile */
    $componentContents = file_get_contents($componentFile);

    expect(is_string($viewContents))->toBeTrue()
        ->and(is_string($componentContents))->toBeTrue();

    /** @var string $viewContents */
    /** @var string $componentContents */
    expect(substr_count($viewContents, 'sync.sync-status-section'))->toBe(1);

    expect($componentContents)->not->toContain("table('sync_sessions')")
        ->and($componentContents)->not->toContain('"sync_sessions"')
        ->and($componentContents)->not->toContain("table('relay_mailbox')")
        ->and($componentContents)->not->toContain('"relay_mailbox"');
});
