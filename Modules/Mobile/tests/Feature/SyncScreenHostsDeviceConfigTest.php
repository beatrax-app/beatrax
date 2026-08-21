<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;

uses(RefreshDatabase::class);

function syncScreenUser(): User
{
    return User::query()->create([
        'username' => 'device-config-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-placement'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The PIN, its idle timeout and biometric unlock describe this device rather than
// a preference, so they sit with pairing and identity on /sync. `#app-lock` was
// once rendered here while the section it named lived on Settings, and a fragment
// only resolves within the current document, so the link did nothing at all.

it('hosts the app-lock controls on the sync surface', function (): void {
    $this->actingAs(syncScreenUser());

    Livewire::test(SyncScreen::class)
        ->assertOk()
        ->assertSeeHtml('id="app-lock"')
        ->assertSeeHtml('data-testid="sync-app-lock"');
});

it('keeps the sync surface as the anchor target its own link points at', function (): void {
    // The gate notice on the devices section links to `#app-lock`, so link and
    // anchor have to render in the same document or the control is silently inert.
    $this->actingAs(syncScreenUser());

    $html = Livewire::test(SyncScreen::class)->html();

    expect($html)->toContain('id="app-lock"');
});

it('does not host the app-lock controls on Settings', function (): void {
    $response = $this->actingAs(syncScreenUser())->get('/settings');

    $response->assertOk();

    // Two live copies of a PIN form is the shape this moved away from. The link-out
    // row went as well, because a settings row whose only content is "this lives
    // somewhere else" is a second entry in the navigation rather than a setting,
    // and Data & Devices is already reachable from the sidebar.
    $response->assertDontSee('data-testid="sync-app-lock"', escape: false);
    $response->assertDontSee('app-lock-link-out', escape: false);
});
