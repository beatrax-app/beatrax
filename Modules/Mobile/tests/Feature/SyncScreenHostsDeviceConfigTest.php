<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;

uses(RefreshDatabase::class);

/*
 * Device-instance configuration lives on `/sync`, not in Settings.
 *
 * The PIN, its idle timeout and biometric unlock describe THIS device rather
 * than a preference, and sync cannot be enabled without a lock — so they sit
 * with pairing, device identity and encryption instead of being a second
 * place to look. Settings keeps a link-out, the same shape Devices & Sync
 * already uses.
 *
 * The anchor is the part worth pinning. `#app-lock` was rendered on the sync
 * surface while the section it named lived on the Settings page, so the
 * "Go to App lock" link did nothing at all — a fragment only resolves within
 * the current document, and nothing failed loudly enough to notice.
 */

function syncScreenUser(): User
{
    return User::query()->create([
        'username' => 'device-config-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-placement'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('hosts the app-lock controls on the sync surface', function (): void {
    $this->actingAs(syncScreenUser());

    Livewire::test(SyncScreen::class)
        ->assertOk()
        ->assertSeeHtml('id="app-lock"')
        ->assertSeeHtml('data-testid="sync-app-lock"');
});

it('keeps the sync surface as the anchor target its own link points at', function (): void {
    // The gate notice on the devices section links to `#app-lock`. Both the
    // link and the anchor must render in the same document or the control is
    // silently inert — exactly the state this replaced.
    $this->actingAs(syncScreenUser());

    $html = Livewire::test(SyncScreen::class)->html();

    expect($html)->toContain('id="app-lock"');
});

it('does not host the app-lock controls on Settings', function (): void {
    $response = $this->actingAs(syncScreenUser())->get('/settings');

    $response->assertOk();

    // The controls must NOT be duplicated here — two live copies of a PIN form
    // is precisely the "Settings is core functionality" shape this moved away
    // from. Settings used to carry a link-out to the moved surface as well;
    // that was removed, because a settings row whose only content is "this
    // lives somewhere else" is a second entry in the navigation, not a
    // setting. Data & Devices is reachable from the sidebar.
    $response->assertDontSee('data-testid="sync-app-lock"', escape: false);
    $response->assertDontSee('app-lock-link-out', escape: false);
});
