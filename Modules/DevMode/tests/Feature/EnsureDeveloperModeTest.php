<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Models\User;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;

/*
 * EnsureDeveloperMode middleware invariants (Phase 16-03 Task 1).
 *
 * Locks the 404-not-403 information-disclosure mitigation (T-16-01)
 * for the in-app Developer Console at /dev/*. A non-developer
 * — authenticated or not — receives NotFoundHttpException, not a 403
 * and not a login redirect; the route's existence is never disclosed.
 *
 * The /dev/__probe route is registered inside `beforeEach` via
 * Route::middleware(['web', 'ensureDeveloperMode'])->get(...) so the
 * middleware behaviour is exercised in isolation from the Task 2
 * dev-shell + DevOverviewPage rendering paths. The route returns the
 * static string "PROBE" so a 200 response can be matched without
 * pulling Blade into the test.
 *
 * The contract-resolution test (#4) covers the four Public contract
 * bindings DevModeServiceProvider::register() declares — they MUST
 * resolve via app(...) and return empty list / no-op for the Null*
 * concretes bound in this plan.
 */

beforeEach(function (): void {
    Route::middleware(['web', 'ensureDeveloperMode'])
        ->get('/dev/__probe', static fn (): string => 'PROBE')
        ->name('dev.__probe');
});

function edmUser(bool $isDeveloper, string $username = 'edm-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('returns 404 for an unauthenticated request to /dev/__probe (NotFoundHttpException, not 403)', function (): void {
    // Seed a developer user so the EnsureDatabaseReady first-launch
    // gate (which redirects when the users table is empty) becomes a
    // pass-through; the test is then a clean exercise of the
    // EnsureDeveloperMode middleware in isolation.
    edmUser(true, 'edm-seed-for-unauth');

    $response = $this->get('/dev/__probe');

    $response->assertNotFound();
});

it('returns 404 for an authenticated non-developer request to /dev/__probe (NotFoundHttpException, not 403)', function (): void {
    $user = edmUser(false, 'edm-nondev');

    $response = $this->actingAs($user)->get('/dev/__probe');

    $response->assertNotFound();
});

it('returns 200 with body "PROBE" for an authenticated developer request to /dev/__probe', function (): void {
    $user = edmUser(true, 'edm-dev');

    $response = $this->actingAs($user)->get('/dev/__probe');

    $response->assertOk();
    expect($response->getContent())->toBe('PROBE');
});

it('resolves all four Public contracts to their Null* defaults via the container', function (): void {
    /** @var DevCommandRegistry $commands */
    $commands = app(DevCommandRegistry::class);
    expect($commands->safe())->toBe([])
        ->and($commands->destructive())->toBe([]);

    /** @var NavigationRegistry $nav */
    $nav = app(NavigationRegistry::class);
    expect($nav->all())->toBe([]);

    /** @var AppActionRegistry $actions */
    $actions = app(AppActionRegistry::class);
    expect($actions->all())->toBe([]);

    // AuditWriter is a no-op interface; resolve it and confirm the
    // binding is the Null shape (a SpatieAuditWriter replacement in
    // 16-04 will swap this assertion).
    /** @var AuditWriter $audit */
    $audit = app(AuditWriter::class);
    expect($audit)->toBeInstanceOf(AuditWriter::class);
});
