<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Mobile\Internal\Http\Livewire\SyncCompleteScreen;

uses(RefreshDatabase::class);

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

// Reaching parity used to redirect straight into the dashboard, so the one moment
// the user was owed an answer went past in a flash of a progress bar.

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
        ->assertSee('until both are on your home network together');
});

it('leads to the app rather than dead-ending', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'Desktop');

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->call('continueToApp')
        ->assertRedirect(route('dashboard'));
});

// Walked at 390x844 with a peer holding an unverifiable author's work back:
// the screen stated the count and its condition exactly as asked, under a
// heading reading "This device is synced". The count is the fix for a first
// sync reporting a whole history it does not have; the heading was still
// reporting one, in the same three inches of screen.
function syncCompleteWithheld(int $userId, int $entries): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toIso8601String();

    $db->connection()->table('sync_withheld_history')->insert([
        'user_id' => $userId,
        'peer_device_id' => '2d4f6a88-1111-4222-8333-444455556666',
        'author_device_id' => 'old-phone',
        'entry_count' => $entries,
        'updated_at' => $now,
    ]);

    // The screen reads the count off the sync's own progress row, which answers
    // zero for a user that has none: without one the fixture would be a screen
    // that had never synced rather than one that synced short.
    $db->connection()->table('mobile_sync_progress')->insert([
        'user_id' => $userId,
        'peer_device_id' => '2d4f6a88-1111-4222-8333-444455556666',
        'records_expected' => 100 + $entries,
        'records_applied' => 100,
        'phase' => 'complete',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('never calls the device synced over history that has not arrived', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'the-mac');
    syncCompleteWithheld((int) $user->id, 155);

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->assertSet('withheldEntries', 155)
        // Both halves on one screen: what is missing, and a heading that does
        // not deny it.
        ->assertSee(Lang::choice('mobile::sync_complete.withheld', 155))
        ->assertSee(Lang::get('mobile::sync_complete.heading_withheld'))
        ->assertDontSee(Lang::get('mobile::sync_complete.heading'));
});

// The positive control, and the other half of the same rule: with nothing held
// back the claim is true and the screen makes it.
it('calls the device synced when nothing is being held back', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'the-mac');

    $this->actingAs($user);

    Livewire::test(SyncCompleteScreen::class)
        ->assertSet('withheldEntries', 0)
        ->assertSee(Lang::get('mobile::sync_complete.heading'))
        ->assertDontSee(Lang::get('mobile::sync_complete.heading_withheld'));
});

// The tab and the heading are one claim read two ways, and a title still saying
// "synced" is the same denial in the place a reader checks second.
it('gives the tab the same claim the heading makes', function (): void {
    $user = syncCompleteUser();
    syncCompletePeer((int) $user->id, 'the-mac');
    syncCompleteWithheld((int) $user->id, 155);

    $this->actingAs($user);

    $title = RenderedMarkup::of($this->get(route('mobile.setup.done'))->getContent())
        ->firstOrFail('title')
        ->text();

    expect($title)->toContain(Lang::get('mobile::sync_complete.heading_withheld'))
        ->and($title)->not->toContain(Lang::get('mobile::sync_complete.heading'));
});
