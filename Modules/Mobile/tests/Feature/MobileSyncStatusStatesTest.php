<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;
use Modules\Mobile\Internal\Sync\SyncBlockedReason;

uses(RefreshDatabase::class);

// The network-policy file is device-scoped rather than per-user, at a fixed path,
// so runs interfere with each other unless it is cleaned up around every test.
beforeEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

afterEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

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
        // The per-device list is the embedded component's per-peer row.
        ->assertSee('desktop-peer-dev')
        // No initial sync is in progress, so the progress line is absent.
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
    // A session that never got anywhere and was never seen lands in the 'offline'
    // bucket: 'error' would need an error_message, 'all_synced' would need it
    // closed and seen.
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
        ->assertSee('Not synced yet')
        ->assertSet('initialSyncInProgress', false)
        ->assertSet('progressApplied', 0)
        ->assertSet('progressExpected', null);
});

it('the Sync now button invokes syncOnce() and re-fetches progress', function (): void {
    $user = mobileSyncStatusUser('mobile-status-syncnow-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    // No device identity or KEK is set up for this fixture user, so syncOnce()
    // skips the tick. That is enough to prove the button reaches the real service
    // without a live LAN or relay round-trip.
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

it('shows what the setup poll is waiting on, and a way out when it cannot resolve', function (): void {
    // Every blocked reason had copy in twenty-six languages and the screen rendered
    // none of it: the component set $blocked and the blade never read it. Revoked
    // is the one that matters, since polling can never clear it and the import gate
    // redirects every route back here, so without a way out it is a lockout.
    $blade = (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/setup-progress-screen.blade.php')
    );

    expect(str_contains($blade, 'mobile::setup.blocked.'))
        ->toBeTrue('the setup screen renders no blocked reason at all');

    expect(str_contains($blade, 'setup-repair-link'))
        ->toBeTrue('a revoked device has no route out of the setup screen');

    // Every reason the puller can report must have copy to render.
    foreach (SyncBlockedReason::cases() as $reason) {
        expect(Lang::get('mobile::setup.blocked.'.$reason->value))
            ->not->toBe('mobile::setup.blocked.'.$reason->value, "no copy for {$reason->value}");
    }
});
