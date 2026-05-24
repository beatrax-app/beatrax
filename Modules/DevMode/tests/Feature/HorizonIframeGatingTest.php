<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;
use Modules\Core\Models\User;

/*
 * Phase 16-06 Task 2 — Horizon iframe gating (D-38 two-signal).
 *
 * The `/dev/horizon` route is registered only when BOTH:
 *   1. `config('app.dev_mode') === true` (env-pinned), AND
 *   2. `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)`
 *      (Horizon is require-dev per Phase 14 D-03; the class is
 *      present on the developer's Herd box but absent from a shipped
 *      `composer install --no-dev` bundle).
 *
 * When either signal is false the route is absent from the
 * route table; an attempted GET returns 404 even for a developer
 * (the EnsureDeveloperMode middleware sits on top, but the route
 * simply doesn't exist — the 404 comes from the router itself).
 *
 * Route registration is `static` (happens at boot before any request
 * reaches it). Tests that need to flip the config('app.dev_mode')
 * signal at runtime use a `RefreshApplication`-style reset; the
 * route registration code lives in `Modules/DevMode/Routes/web.php`
 * and is exercised on every `loadRoutesFrom()` boot.
 */

function horizonGatingUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

function horizonGatingSetDevModeFlag(bool $on): void
{
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('app.dev_mode', $on);
}

/**
 * The DevModeServiceProvider's `loadRoutesFrom()` fires once at boot
 * — before any test flips `config('app.dev_mode')`. To exercise the
 * D-38 conditional registration body at the test layer, the test
 * resets the router's route collection and re-includes the routes
 * file directly. Laravel's RouteCollection keeps a separate name
 * lookup table (`refreshNameLookups()`) — `Route::has()` reads that
 * table, NOT the live route list — so the re-include MUST be
 * followed by a refresh.
 */
function horizonGatingReloadRoutes(): void
{
    /** @var Router $router */
    $router = app(Router::class);

    // Drop every existing /dev/* route so the re-include lands a clean
    // re-registration of the entire group. Building a new
    // RouteCollection is the canonical Laravel route-reset.
    Route::setRoutes(new RouteCollection);

    // Re-evaluate the routes file body — the D-38 conditional reads
    // the live `config('app.dev_mode')` value when the file is
    // require'd, so a config flip BEFORE this call picks up the new
    // value on the re-include.
    require base_path('Modules/DevMode/Routes/web.php');

    // Refresh the router's named-route lookup; without this,
    // `Route::has('dev.horizon')` reads stale state regardless of
    // whether the route was just registered.
    $router->getRoutes()->refreshNameLookups();
}

it('asserts that the Horizon package class IS present in the test environment (precondition for the gating tests below)', function (): void {
    // The full D-38 gate is a two-signal check; the class signal is
    // platform-driven (Horizon is require-dev). On the developer's
    // Herd box AND in CI it should be present, so the test below
    // can exercise the env-flag side of the gate. If this assertion
    // fails the test environment is broken — the rest of the gating
    // tests then exercise only the env-flag side.
    expect(class_exists(HorizonServiceProvider::class))
        ->toBeTrue('Horizon package is expected to be installed in the dev/test environment.');
});

it('does NOT register the dev.horizon route when config("app.dev_mode") is null (the shipped-build default)', function (): void {
    horizonGatingSetDevModeFlag(false);

    // Reset routes so the conditional registration code is re-evaluated.
    // Reload the routes file directly; the conditional registration runs
    // once per boot, and the boot already happened against the test app's
    // current config snapshot.
    horizonGatingReloadRoutes();

    expect(Route::has('dev.horizon'))->toBeFalse();
});

it('DOES register the dev.horizon route when config("app.dev_mode")===true AND the Horizon class exists', function (): void {
    horizonGatingSetDevModeFlag(true);

    horizonGatingReloadRoutes();

    expect(Route::has('dev.horizon'))->toBeTrue();
});

it('returns 404 for GET /dev/horizon when the route is NOT registered (dev_mode off)', function (): void {
    horizonGatingSetDevModeFlag(false);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-gate-off');

    $response = $this->actingAs($user)->get('/dev/horizon');

    $response->assertNotFound();
});

it('returns 200 for GET /dev/horizon as a developer when both signals are true; the response embeds an iframe pointing at /horizon', function (): void {
    horizonGatingSetDevModeFlag(true);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-gate-on');

    $response = $this->actingAs($user)->get('/dev/horizon');

    $response->assertOk();
    $response->assertSee('<iframe', escape: false);
    $response->assertSee('src="/horizon"', escape: false);
});

it('returns 404 for GET /dev/horizon as a non-developer EVEN when both signals are true (EnsureDeveloperMode gate on top)', function (): void {
    horizonGatingSetDevModeFlag(true);
    horizonGatingReloadRoutes();

    horizonGatingUser('hz-seed-for-non-dev'); // seed first user so EnsureDatabaseReady is happy
    $user = horizonGatingUser('hz-nondev', false);

    $response = $this->actingAs($user)->get('/dev/horizon');

    $response->assertNotFound();
});

it('drops the Horizon sidebar nav item DOM-absent when the dev.horizon route is NOT registered', function (): void {
    horizonGatingSetDevModeFlag(false);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-sidebar-off');

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    // Per D-38 the item is DOM-absent (not nav-disabled) when the
    // route is not registered. No `>Horizon<` anchor text and no
    // dev.horizon route stub should appear in the rendered HTML.
    expect(str_contains($html, '>Horizon<'))
        ->toBeFalse('Horizon nav item should be DOM-absent when dev.horizon is not registered.');
    expect(str_contains($html, 'href="/dev/horizon"'))
        ->toBeFalse('Sidebar should not link to /dev/horizon when the route is not registered.');
});

it('renders the Horizon sidebar nav item WITHOUT nav-disabled when the dev.horizon route IS registered', function (): void {
    horizonGatingSetDevModeFlag(true);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-sidebar-on');

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    // The Horizon item should now be present in the sidebar. Use a
    // tight regex to capture only the matching <a> tag.
    preg_match_all('#<a\s+href="[^"]*"\s+class="side-item([^"]*)"[^>]*>[\s\S]*?Horizon[\s\S]*?</a>#', $html, $matches);

    expect($matches[0])->not->toBeEmpty('Horizon sidebar entry should render when dev.horizon is registered');
    foreach ($matches[1] as $classes) {
        expect($classes)->not->toContain('nav-disabled', 'Horizon nav item should not be nav-disabled when its route is registered');
    }
});
