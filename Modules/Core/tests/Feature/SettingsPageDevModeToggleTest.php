<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

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

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setDevMode', true);

    // Re-fetch from the DB to stand in for a fresh session.
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

    $this->actingAs($user)->get('/dev')->assertNotFound();

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setDevMode', true);

    // Re-authenticate so the guard sees the updated `is_developer` — the
    // in-test request rebinds the user from the guard on each call.
    $reloaded = User::query()->find($user->id);
    $this->actingAs($reloaded)->get('/dev')->assertOk();
});
