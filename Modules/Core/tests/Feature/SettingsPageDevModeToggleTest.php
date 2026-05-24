<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

/*
 * SettingsPage Developer toggle (Phase 16-03 Task 3 / DEVUI-01).
 *
 * Locks the four behaviours per plan 16-03 Task 3:
 *   1. A non-developer mounting the page sees the toggle in "off" state.
 *   2. Calling setDevMode(true) writes users.is_developer=true via
 *      the User Eloquent model and the component re-renders in "on"
 *      state.
 *   3. The flip is DB-persisted (not session-scoped) — reloading the
 *      User model from storage reflects the new value.
 *   4. After toggling on, the /dev route stops returning 404 (the
 *      EnsureDeveloperMode middleware now accepts the request).
 *
 * The test seeds two users so the EnsureDatabaseReady first-launch
 * gate (which redirects when the users table is empty) becomes a
 * pass-through.
 */

function sdmtUser(bool $isDeveloper, string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('shows the Developer toggle in off state for a non-developer user', function (): void {
    $user = sdmtUser(false, 'sdmt-nondev');

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->assertSet('isDeveloper', false);
});

it('flips users.is_developer to true when setDevMode(true) is called', function (): void {
    $user = sdmtUser(false, 'sdmt-flip');

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->assertSet('isDeveloper', false)
        ->call('setDevMode', true)
        ->assertSet('isDeveloper', true);

    expect(User::query()->find($user->id)->is_developer)->toBeTrue();
});

it('persists the flip across logout/login (DB-persisted, not session-scoped)', function (): void {
    $user = sdmtUser(false, 'sdmt-persist');

    // First session: flip via the Livewire component.
    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setDevMode', true);

    // Simulate a fresh session by re-fetching the user from the DB
    // and mounting the page again as if logging back in.
    $reloaded = User::query()->find($user->id);
    expect($reloaded->is_developer)->toBeTrue();

    Livewire::actingAs($reloaded)
        ->test(SettingsPage::class)
        ->assertSet('isDeveloper', true);
});

it('unlocks /dev (200 instead of 404) after the user toggles Developer on', function (): void {
    // Seed a separate developer user first so EnsureDatabaseReady
    // does not bounce the non-developer's request to /welcome before
    // EnsureDeveloperMode runs.
    sdmtUser(true, 'sdmt-seed-for-gate');

    $user = sdmtUser(false, 'sdmt-unlock');

    // Pre-toggle: /dev returns 404 for the non-developer.
    $this->actingAs($user)->get('/dev')->assertNotFound();

    // Flip the toggle via the Livewire component.
    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setDevMode', true);

    // Re-fetch and re-authenticate so the auth guard sees the updated
    // `is_developer` value (the in-test request rebinds the user from
    // the guard on each call).
    $reloaded = User::query()->find($user->id);
    $this->actingAs($reloaded)->get('/dev')->assertOk();
});
