<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\SyncCompleteScreen;

uses(RefreshDatabase::class);

/*
 * The confirmation the setup gate hands off to.
 *
 * Reaching parity used to redirect straight into the dashboard, so the one
 * moment the user was owed an answer — did it work, and what happens now —
 * went past in a flash of a progress bar. This screen answers both, and its
 * copy has to stay true to what this particular device can actually do: the
 * away-from-home promise is only honest once a relay is configured.
 */
function syncCompleteUser(): User
{
    return User::query()->create([
        'username' => 'sync-done-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function syncCompletePeer(int $userId, string $name): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toIso8601String();

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => '2d4f6a88-1111-4222-8333-444455556666',
        'name' => $name,
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('names the device it caught up from', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'Wessel’s iMac');

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->assertSet('peerName', 'Wessel’s iMac')
        ->assertSee('Wessel’s iMac');
});

// A completed catch-up that copied nothing is a real outcome — the devices
// were already level — and reporting it as "0 records" reads as a failure.
it('reports parity rather than zero when there was nothing to copy', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'Desktop');

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->assertSet('recordsApplied', 0)
        ->assertSee('nothing new to copy')
        ->assertDontSee('0 records');
});

// The relay line promises that changes travel while the devices are apart.
// That is only true once an endpoint exists, so the copy has to follow it.
it('only promises off-network sync when a relay is actually configured', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'Desktop');

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->assertSet('hasRelay', false)
        ->assertSee('sync the next time both are on your home network');
});

it('leads to the app rather than dead-ending', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'Desktop');

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->call('continueToApp')
        ->assertRedirect(route('dashboard'));
});
