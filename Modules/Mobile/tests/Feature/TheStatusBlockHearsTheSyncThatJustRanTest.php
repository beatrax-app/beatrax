<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\SyncEvents;

uses(RefreshDatabase::class);

// Two components share the devices screen: the sync screen owns the button and
// reports what the attempt did, and the status block above it says whether this
// device has ever synced. The block reads that once, at mount, and nothing polls
// it — so on a real iPhone it went on saying "Not yet synced" directly above
// "Synced with your other device", each contradicting the other, until the page
// was reloaded. A reader has no way to tell which of the two is stale.

beforeEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

afterEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

function statusHearsUser(): User
{
    return User::query()->create([
        'username' => 'status-hears-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function statusHearsPeerFor(User $user): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'desktop-peer-'.bin2hex(random_bytes(4)),
        'name' => 'Study desktop',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'alpha bravo charlie',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:01:00Z',
        'last_seen_at' => '2026-08-01T10:01:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:01:00Z',
    ]);
}

// A session that opened and closed cleanly: the row a finished sync leaves,
// and what "all devices up to date" is read off.
function statusHearsSessionFor(User $user): void
{
    app(DatabaseManager::class)->connection()->table('sync_sessions')->insert([
        'user_id' => $user->id,
        'local_device_id' => 'this-phone',
        'peer_device_id' => 'study-desktop',
        'status' => 'closed',
        'error_message' => null,
        'last_seen_at' => '2026-08-01T10:05:00Z',
        'connected_at' => '2026-08-01T10:04:00Z',
        'created_at' => '2026-08-01T10:04:00Z',
        'updated_at' => '2026-08-01T10:05:00Z',
    ]);
}

it('tells the status block that a sync just ran', function (): void {
    $user = statusHearsUser();
    statusHearsPeerFor($user);
    $this->actingAs($user);

    Livewire::test(SyncScreen::class)
        ->call('syncNow')
        ->assertDispatched(SyncEvents::COMPLETED);
});

// A press with nothing to talk to runs no sync, so there is no new answer for
// the block to re-read and nothing to announce.
it('announces nothing when the press had no peer to reach', function (): void {
    $this->actingAs(statusHearsUser());

    Livewire::test(SyncScreen::class)
        ->call('syncNow')
        ->assertNotDispatched(SyncEvents::COMPLETED);
});

// The block mounted before the session row existed, which is the order the
// screen produces: it is drawn, then the button beneath it runs a sync.
it('re-reads its answer when it hears one, instead of keeping the one it mounted with', function (): void {
    $user = statusHearsUser();
    statusHearsPeerFor($user);
    $this->actingAs($user);

    $block = Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'unknown')
        ->assertSet('lastSyncedHuman', null);

    statusHearsSessionFor($user);

    $block->call('onSyncCompleted')
        ->assertSet('overallStatus', 'all_synced')
        ->assertNotSet('lastSyncedHuman', null);
});
