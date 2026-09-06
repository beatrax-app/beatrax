<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

function statusUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertSession(DatabaseManager $db, User $user, array $overrides = []): int
{
    $defaults = [
        'user_id' => $user->id,
        'local_device_id' => 'local-dev-a',
        'peer_device_id' => 'peer-dev-b',
        'status' => 'closed',
        'error_message' => null,
        'last_seen_at' => '2026-06-15T10:00:00Z',
        'connected_at' => '2026-06-15T09:55:00Z',
        'created_at' => '2026-06-15T09:55:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ];

    return (int) $db->connection()->table('sync_sessions')->insertGetId(
        array_merge($defaults, $overrides)
    );
}

it('mounts cleanly with unknown status when no sessions exist', function (): void {
    $user = statusUser('status-no-sessions');
    $this->actingAs($user);

    Livewire::test(SyncStatusSection::class)
        ->assertStatus(200)
        ->assertSet('overallStatus', 'unknown')
        ->assertSet('peerStatuses', [])
        ->assertSet('lastSyncedHuman', null);
});

it('shows all_synced status when a peer session is closed with last_seen_at', function (): void {
    $user = statusUser('status-all-synced');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertSession($db, $user, [
        'status' => 'closed',
        'last_seen_at' => '2026-06-15T10:00:00Z',
    ]);

    // Freeze time so the "last synced" calculation is deterministic.
    $now = CarbonImmutable::parse('2026-06-15T10:05:00Z');
    $this->app->bind(Clock::class, fn () => new class($now) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $frozen) {}

        public function now(): CarbonImmutable
        {
            return $this->frozen;
        }
    });

    Livewire::test(SyncStatusSection::class)
        ->assertStatus(200)
        ->assertSet('overallStatus', 'all_synced')
        ->assertSee('peer-dev-b');
});

it('shows syncing status when a session is active', function (): void {
    $user = statusUser('status-syncing');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertSession($db, $user, [
        'status' => 'active',
        'last_seen_at' => '2026-06-15T10:00:00Z',
    ]);

    // An active row claims a peer is mid-exchange right now, so it is only
    // read as one near the instant that stamped it.
    $now = CarbonImmutable::parse('2026-06-15T10:00:30Z');
    $this->app->bind(Clock::class, fn () => new class($now) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $frozen) {}

        public function now(): CarbonImmutable
        {
            return $this->frozen;
        }
    });

    Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'syncing');
});

it('shows error status and error label when a session is failed with an error message', function (): void {
    $user = statusUser('status-error');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertSession($db, $user, [
        'status' => 'failed',
        'error_message' => 'Noise handshake verify failed: static key mismatch',
        'last_seen_at' => '2026-06-15T10:00:00Z',
    ]);

    $component = Livewire::test(SyncStatusSection::class)
        ->assertStatus(200)
        ->assertSet('overallStatus', 'error');

    $component->assertSee('Handshake / verify failed');
});

// An unreachable relay is the ordinary case and reads as offline. The row's own
// label already said "Relay unreachable" while the banner over it said an error
// needed attention, which is two answers to one question.
it('surfaces the relay unreachable error label when the error mentions relay', function (): void {
    $user = statusUser('status-relay-error');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertSession($db, $user, [
        'status' => 'failed',
        'error_message' => 'relay endpoint unreachable: connection refused',
        'last_seen_at' => null,
    ]);

    $component = Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'offline');

    $component->assertSee('Relay unreachable');
});

it('surfaces the cant reach peer error label when error mentions connection timeout', function (): void {
    $user = statusUser('status-reach-error');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertSession($db, $user, [
        'status' => 'failed',
        'error_message' => 'connection timeout after 30s',
        'last_seen_at' => null,
    ]);

    $component = Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'offline');

    $component->assertSee("Can't reach peer");
});

it('isolates sessions by user — another user sessions do not appear', function (): void {
    $user = statusUser('status-isolation-a');
    $otherUser = statusUser('status-isolation-b');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    insertSession($db, $otherUser, [
        'status' => 'active',
        'peer_device_id' => 'other-users-peer',
    ]);

    Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'unknown')
        ->assertSet('peerStatuses', [])
        ->assertDontSee('other-users-peer');
});

it('SyncStatusService is registered as a singleton and returns user-scoped rows', function (): void {
    $user = statusUser('status-service-singleton');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    insertSession($db, $user, [
        'status' => 'active',
        'peer_device_id' => 'singleton-peer',
    ]);

    /** @var SyncStatusService $service */
    $service = app(SyncStatusService::class);

    $rows = $service->peerStatuses($user->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->peer_device_id)->toBe('singleton-peer')
        ->and($rows[0]->status)->toBe('active');
});

it('component does not contain raw sync_sessions query (boundary rule)', function (): void {
    $componentFile = realpath(__DIR__.'/../../Public/Http/Livewire/SyncStatusSection.php');

    expect($componentFile)->not->toBeFalse()
        ->and(is_string($componentFile))->toBeTrue();

    /** @var string $componentFile */
    $contents = file_get_contents($componentFile);

    expect(is_string($contents))->toBeTrue();

    /** @var string $contents */
    expect($contents)->not->toContain("table('sync_sessions')")
        ->and($contents)->not->toContain('"sync_sessions"');
});
