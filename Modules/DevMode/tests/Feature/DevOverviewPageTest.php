<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * DevOverviewPage rendering invariants (Phase 16-03 Task 2).
 *
 * Locks the /dev route + the dev-shell layout + the 9-item dev
 * sidebar (8 of them `nav-disabled` in this plan because their
 * routes land in 16-04/05/06/07; the Overview link is the only
 * enabled entry).
 *
 * The test seeds a developer user as the first user so the Phase 15
 * EnsureDatabaseReady first-launch gate (which redirects when the
 * users table is empty) becomes a pass-through, isolating the test
 * from the welcome-screen redirect path.
 */

function devOverviewUser(bool $isDeveloper, string $username = 'devov-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('renders the /dev overview page for an authenticated developer with the dev-shell sidebar', function (): void {
    $user = devOverviewUser(true, 'devov-dev');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $response->assertSee('Dev Console', escape: false); // sidebar heading
    $response->assertSee('Overview', escape: false); // page heading
    $response->assertSee('More Dev Console panels arriving in Wave 5', escape: false);
});

it('returns 404 from /dev for an authenticated non-developer (EnsureDeveloperMode gate)', function (): void {
    devOverviewUser(true, 'devov-seed-for-gate');
    $user = devOverviewUser(false, 'devov-nondev');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertNotFound();
});

it('renders all 9 dev-sidebar nav items with Overview + Artisan + Audit enabled (16-04b registers dev.artisan + dev.audit)', function (): void {
    $user = devOverviewUser(true, 'devov-nav');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    // Every nav item label must appear in the rendered sidebar.
    foreach (['Overview', 'Artisan', 'Audit', 'Logs', 'Queue', 'Doctor', 'SQL', 'Horizon', 'System'] as $label) {
        expect(str_contains($html, $label))
            ->toBeTrue("Dev sidebar missing nav item: {$label}");
    }

    // After 16-04b registers the dev.artisan + dev.audit routes, only
    // SIX nav-disabled entries remain (Logs / Queue / Doctor / SQL /
    // Horizon / System). The count drops further as 16-05 + 16-06 +
    // 16-07 land their routes; 16-03 originally expected eight.
    $disabledCount = substr_count($html, 'nav-disabled');
    expect($disabledCount)->toBe(
        6,
        "Expected exactly 6 nav-disabled entries (every dev sidebar nav item except Overview/Artisan/Audit after 16-04b), saw {$disabledCount}.",
    );
});

it('declares the 220px dev-shell sidebar width (--side-w-dev token or inline)', function (): void {
    $user = devOverviewUser(true, 'devov-width');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    // The dev sidebar width comes from --side-w-dev (220px). Accept
    // either the inline style declaration on the <aside> root or the
    // literal `220px` substring somewhere in the rendered HTML.
    expect($html)->toContain('--side-w-dev')
        ->and($html)->toContain('220px');
});

it('embeds the "Back to app" foot link routing to /', function (): void {
    $user = devOverviewUser(true, 'devov-back');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Back to app')
        ->and($html)->toContain('dev-back-link');
});
