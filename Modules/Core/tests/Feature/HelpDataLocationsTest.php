<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\HelpDataLocations;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;

function hdlUser(bool $isDeveloper, string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('returns 200 and renders the verbatim "Where is my data?" page title', function (): void {
    $user = hdlUser(false, 'hdl-title');

    $response = $this->actingAs($user)->get('/help/data-locations');

    $response->assertOk();
    $response->assertSeeText('Where is my data?');
});

it('renders the verbatim local-only intro paragraph', function (): void {
    $user = hdlUser(false, 'hdl-intro');

    Livewire::actingAs($user)->test(HelpDataLocations::class)
        ->assertSeeText('Beatrax stores everything on this device. Nothing is sent to a server, nothing syncs to the cloud, nothing leaves this device without you exporting it.');
});

it('renders all three resolved paths exactly as UserDataPathService reports them', function (): void {
    $user = hdlUser(false, 'hdl-paths');

    /** @var UserDataPathService $paths */
    $paths = $this->app->make(UserDataPathService::class);

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSee($paths->databasePath());
    $component->assertSee($paths->secrets());
    $component->assertSee($paths->framework());
});

it('renders an aria-labeled copy-to-clipboard button for every path row', function (): void {
    $user = hdlUser(false, 'hdl-aria');

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSee('aria-label="Copy SQLite database path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy OAuth secrets path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy Brand assets + caches path to clipboard"', escape: false);
});

it('renders the primary "Export everything as ZIP" CTA when Dev Mode is ON', function (): void {
    $user = hdlUser(true, 'hdl-devon');

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSeeText('Export everything as ZIP');
    $component->assertDontSeeText('Dev Mode is off.');
});

it('renders the verbatim instructional fallback paragraph when Dev Mode is OFF', function (): void {
    $user = hdlUser(false, 'hdl-devoff');

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSeeText('Dev Mode is off.');
    $component->assertSeeText('Enable Dev Mode in Settings');
    $component->assertSeeText('Manually copy the folders above');

    $component->assertDontSeeText('Export everything as ZIP');
});

it('renders Section 3 "Deleting your data" verbatim with the no-telemetry line', function (): void {
    $user = hdlUser(false, 'hdl-delete');

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSeeText('Deleting your data');
    $component->assertSeeText('To remove Beatrax and every trace of your data');
    $component->assertSeeText('Drag Beatrax to the Trash');
    $component->assertSeeText('Delete the folders listed above');

    // assertSeeText escapes the needle by default, turning the apostrophe into
    // `&#039;`, while the stripped-tags haystack still holds a raw `'` — hence
    // `escape: false`.
    $component->assertSeeText("There's no telemetry to opt out of and no remote account to close.", escape: false);
});

it('resolves paths only through the authenticated user (cross-user safety)', function (): void {
    // UserDataPathService is per-app, not per-user-input, so nothing userB
    // supplies can influence what userA's render shows.
    $userA = hdlUser(false, 'hdl-cross-a');
    $userB = hdlUser(true, 'hdl-cross-b');

    /** @var UserDataPathService $paths */
    $paths = $this->app->make(UserDataPathService::class);

    $component = Livewire::actingAs($userA)->test(HelpDataLocations::class);

    $component->assertSee($paths->databasePath());

    // Dev Mode resolves through the AUTHENTICATED user only — userB's
    // is_developer=true flag must not bleed into userA's rendering.
    $component->assertDontSeeText('Export everything as ZIP');
    $component->assertSeeText('Dev Mode is off.');

    // Guards the fixture: userB really does carry the flag.
    expect($userB->is_developer)->toBeTrue();
});
