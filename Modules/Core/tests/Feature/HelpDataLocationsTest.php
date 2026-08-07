<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\HelpDataLocations;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;

/*
 * /help/data-locations — the user-facing "Where is my data?" page that
 * makes the local-only privacy promise tangible. The page is a
 * Livewire component that resolves the three load-bearing paths
 * (SQLite database, OAuth secrets, framework caches) through
 * UserDataPathService and renders them with copy-to-clipboard
 * affordances. Section 2 (Export everything) branches on the
 * authenticated user's `is_developer` flag: Dev Mode ON renders the
 * primary CTA; Dev Mode OFF renders the instructional fallback.
 *
 * Tests cover:
 *  (1) page renders 200 with the verbatim "Where is my data?" title
 *  (2) verbatim intro paragraph rendered
 *  (3) all three resolved paths appear in the rendered HTML, exactly
 *      as UserDataPathService reports them
 *  (4) each path row carries the literal `aria-label="Copy {type}
 *      path to clipboard"` attribute
 *  (5) Dev Mode ON renders the "Export everything as ZIP" primary
 *      button; Dev Mode OFF renders the verbatim instructional
 *      fallback paragraph and does NOT render the primary CTA
 *  (6) Section 3 "Deleting your data" renders verbatim including the
 *      "no telemetry to opt out of" line
 *  (7) Cross-user safety: the page only resolves through the
 *      authenticated user (no request-supplied user id reaches the
 *      path resolver), so userA's render matches userA's paths
 *      regardless of any other seeded users in the database.
 */

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
        ->assertSeeText('beatrax stores everything on this device. Nothing is sent to a server, nothing syncs to the cloud, nothing leaves this device without you exporting it.');
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
    // The Dev Mode OFF fallback heading must NOT render in this branch.
    $component->assertDontSeeText('Dev Mode is off.');
});

it('renders the verbatim instructional fallback paragraph when Dev Mode is OFF', function (): void {
    $user = hdlUser(false, 'hdl-devoff');

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    // The verbatim fallback prose per 17-UI-SPEC § Section 2 fallback.
    $component->assertSeeText('Dev Mode is off.');
    $component->assertSeeText('Enable Dev Mode in Settings');
    $component->assertSeeText('Manually copy the folders above');

    // The primary CTA must NOT render in this branch.
    $component->assertDontSeeText('Export everything as ZIP');
});

it('renders Section 3 "Deleting your data" verbatim with the no-telemetry line', function (): void {
    $user = hdlUser(false, 'hdl-delete');

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSeeText('Deleting your data');
    $component->assertSeeText('To remove beatrax and every trace of your data');
    $component->assertSeeText('Drag beatrax to the Trash');
    $component->assertSeeText('Delete the folders listed above');

    // Livewire's assertSeeText HTML-escapes the needle by default
    // (turning the apostrophe into `&#039;`) before searching the
    // stripped-tags haystack, which still contains the raw `'`
    // character. Pass `escape: false` so the assertion compares the
    // literal needle against the rendered text.
    $component->assertSeeText("There's no telemetry to opt out of and no remote account to close.", escape: false);
});

it('resolves paths only through the authenticated user (cross-user safety)', function (): void {
    // Two users exist; only userA is authenticated. The page must render
    // the canonical paths through UserDataPathService — which is per-app
    // (not per-user-input) — so no value supplied by a different user
    // can influence the rendered paths.
    $userA = hdlUser(false, 'hdl-cross-a');
    $userB = hdlUser(true, 'hdl-cross-b');

    /** @var UserDataPathService $paths */
    $paths = $this->app->make(UserDataPathService::class);

    $component = Livewire::actingAs($userA)->test(HelpDataLocations::class);

    // Sanity: userA sees the canonical paths.
    $component->assertSee($paths->databasePath());

    // Dev Mode resolves through the AUTHENTICATED user only — userB's
    // is_developer=true flag must not bleed into userA's rendering.
    $component->assertDontSeeText('Export everything as ZIP');
    $component->assertSeeText('Dev Mode is off.');

    // The page never reads from the second user's row.
    expect($userB->is_developer)->toBeTrue();
});
