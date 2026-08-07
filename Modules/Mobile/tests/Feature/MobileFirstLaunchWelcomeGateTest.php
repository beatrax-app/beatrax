<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Livewire\MobileWelcomeScreen;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;

/*
 * 15-onboarding: mobile first-run gate + welcome screen.
 *
 * Mirrors Modules\Desktop\tests\Feature\FirstLaunchBootstrapTest +
 * WelcomeScreenRedirectTest, scoped to the mobile mirror:
 *
 *   - MobileEnsureDatabaseReady redirects a 0-user request to
 *     `mobile.welcome` and passes a post-signup request through.
 *   - The exempt list (mobile.welcome/signup/mobile.pair/mobile.setup +
 *     the livewire.update suffix) never redirects.
 *   - MobileWelcomeScreen renders both CTAs on a fresh install and bounces
 *     an already-set-up device to the dashboard.
 *   - /signup stays reachable at 0 users and 404s once a user exists (the
 *     existing FirstUserOnlyMiddleware behavior — light regression check).
 *
 * PRODUCTION WIRING LIMITATION: unlike the desktop gate (registered on
 * THIS root's own bootstrap/app.php, so the root test harness's router
 * reflects it directly), MobileEnsureDatabaseReady is registered on
 * `mobile-app/bootstrap/app.php` — a SEPARATE Laravel application root
 * this test process never boots (mirrors 15-DEVICE-UAT.md /
 * NativeServiceProviderPluginWiringTest's own documented limitation for
 * mobile-only wiring). The router-introspection assertion the desktop
 * suite uses (`$router->getMiddlewareGroups()['web']`) is therefore not
 * available here. Production wiring is instead verified two ways: (1) a
 * belt-and-suspenders `file_get_contents()` source-string assertion on
 * `mobile-app/bootstrap/app.php` itself (the class IS autoloadable from
 * this root, since Modules/Mobile ships in the root repo, unlike the
 * mobile-app-vendor-only NativePHP plugin classes), and (2) the stub-route
 * tests below, which exercise `handle()` end-to-end through the real HTTP
 * kernel — just not through the mobile-app-specific `web` group
 * registration itself.
 *
 * SHARED-HARNESS COLLISION: this test process boots the ROOT app
 * (`bootstrap/app.php`, Desktop-oriented), which appends the DESKTOP's own
 * `EnsureDatabaseReady` to the shared `web` group. In real production the
 * two roots are separate Laravel installations — the desktop gate never
 * loads under the mobile-app root at all — but under this shared test
 * harness a 0-user request through the real `web` group hits the
 * desktop gate FIRST, which does not know about `mobile.*` route names
 * and bounces the request to `desktop.welcome` (`/welcome`) before
 * `MobileEnsureDatabaseReady` ever runs. Tests that need to exercise a
 * genuine fresh-install request through the real `web` group therefore
 * neutralize the desktop gate with `$this->withoutMiddleware
 * (EnsureDatabaseReady::class)` — a correction for the test-harness
 * artifact, not a behavior change (the desktop gate is simply absent from
 * the mobile-app root in production).
 */

function mobileWelcomeGateUser(string $suffix = ''): User
{
    return User::query()->create([
        'username' => 'mobile-welcome-gate-'.($suffix !== '' ? $suffix.'-' : '').bin2hex(random_bytes(4)),
        'password' => bcrypt('mobile-welcome-gate-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// -----------------------------------------------------------------------
// MobileEnsureDatabaseReady — core redirect behavior
// -----------------------------------------------------------------------

it('redirects a fresh (0-user) request to mobile.welcome', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app['router']
        ->middleware(['web', MobileEnsureDatabaseReady::class])
        ->get('/__test/mobile-gated', static fn () => 'GATED');

    expect(User::query()->count())->toBe(0);

    $this->get('/__test/mobile-gated')
        ->assertRedirect(route('mobile.welcome'));
});

it('lets the request through once at least one user exists (no redirect)', function (): void {
    $this->app['router']
        ->middleware(['web', MobileEnsureDatabaseReady::class])
        ->get('/__test/mobile-gated-ok', static fn () => 'GATED-OK');

    mobileWelcomeGateUser('post-signup');

    $this->get('/__test/mobile-gated-ok')
        ->assertOk()
        ->assertSee('GATED-OK');
});

// -----------------------------------------------------------------------
// Exemptions
// -----------------------------------------------------------------------

it('exempts the mobile.welcome route itself so it does not loop', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    expect(User::query()->count())->toBe(0);

    $this->get(route('mobile.welcome'))
        ->assertOk()
        ->assertSee('Welcome to beatrax');
});

it('exempts the signup route so the welcome to create-account chain does not loop', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->get(route('signup'))
        ->assertOk();
});

it('exempts routes named exactly "mobile.import" via prefix matching (Phase 15 import-join)', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app['router']
        ->middleware(['web', MobileEnsureDatabaseReady::class])
        ->get('/__test/mobile-import-stub', static fn () => 'MOBILE-IMPORT-STUB')
        ->name('mobile.import');

    expect(User::query()->count())->toBe(0);

    $this->get('/__test/mobile-import-stub')
        ->assertOk()
        ->assertSee('MOBILE-IMPORT-STUB');
});

it('exempts routes named exactly "mobile.pair" via prefix matching', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app['router']
        ->middleware(['web', MobileEnsureDatabaseReady::class])
        ->get('/__test/mobile-pair-stub', static fn () => 'MOBILE-PAIR-STUB')
        ->name('mobile.pair');

    expect(User::query()->count())->toBe(0);

    $this->get('/__test/mobile-pair-stub')
        ->assertOk()
        ->assertSee('MOBILE-PAIR-STUB');
});

it('exempts routes named exactly "mobile.setup" via prefix matching', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app['router']
        ->middleware(['web', MobileEnsureDatabaseReady::class])
        ->get('/__test/mobile-setup-stub', static fn () => 'MOBILE-SETUP-STUB')
        ->name('mobile.setup');

    expect(User::query()->count())->toBe(0);

    $this->get('/__test/mobile-setup-stub')
        ->assertOk()
        ->assertSee('MOBILE-SETUP-STUB');
});

it('exempts the Livewire AJAX update endpoint so the signup submit POST is not bounced', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/mobile-fake-livewire/update', static fn () => 'LW-UPDATE')
        ->name('mobile-test-livewire.update');

    $this->post('/__test/mobile-fake-livewire/update')
        ->assertOk()
        ->assertSee('LW-UPDATE');
});

it('does NOT exempt a route that merely contains the livewire.update substring without the suffix (control)', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app['router']
        ->middleware(['web', MobileEnsureDatabaseReady::class])
        ->get('/__test/mobile-livewire-update-ish', static fn () => 'NOT-EXEMPT')
        ->name('livewire.update-not-actually-a-suffix-match');

    expect(User::query()->count())->toBe(0);

    // The stub's name ends with "...suffix-match", not "livewire.update"
    // itself, so it is NOT exempt — control assertion proving the
    // suffix matcher (not a loose substring match) is doing the work.
    $this->get('/__test/mobile-livewire-update-ish')
        ->assertRedirect(route('mobile.welcome'));
});

// -----------------------------------------------------------------------
// Production wiring (mobile-app/bootstrap/app.php source assertion — see
// class-level docblock for why a router-introspection assertion is not
// available here)
// -----------------------------------------------------------------------

it('registers MobileEnsureDatabaseReady on the mobile-app root web middleware group', function (): void {
    $source = (string) file_get_contents(base_path('mobile-app/bootstrap/app.php'));

    expect($source)->toContain('use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;');
    expect($source)->toContain(MobileEnsureDatabaseReady::class);
    // PREPENDED, never appended: appending leaves the gate behind
    // Authenticate once the framework sorts the stack by priority, which
    // is the whole failure this middleware exists to prevent. The
    // ordering itself is asserted for real below.
    expect($source)->toContain("\$middleware->prependToGroup('web', MobileEnsureDatabaseReady::class);");
    expect($source)->not->toContain("            MobileEnsureDatabaseReady::class,\n        ]);");
    // ->group('repo-root-only'): the path is resolved relative to the repo
    // root, so running from the mobile-app root looks for
    // mobile-app/mobile-app/bootstrap/app.php.
})->group('repo-root-only');

it('runs the fresh-install gate before the auth middleware it exists to pre-empt', function (): void {
    // The bug this pins: registering the gate by appending it to the `web`
    // group put it at index 7 while SortedMiddleware hoisted Authenticate
    // (an AuthenticatesRequests implementor) to index 5, so a 0-user device
    // threw AuthenticationException and landed on the desktop `/login`
    // instead of the mobile welcome screen. List position is not run
    // position — only the sorted stack proves the ordering.
    $router = $this->app['router'];

    // This root never boots mobile-app/bootstrap/app.php, so mirror the one
    // line of production wiring under test and let the framework's own
    // sorter decide the outcome.
    $router->prependMiddlewareToGroup('web', MobileEnsureDatabaseReady::class);

    $route = $router->middleware(['web', 'auth'])
        ->get('/__test/mobile-ordering', static fn () => 'OK');

    $sorted = array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        'is_string',
    ));

    $gateIndex = array_search(MobileEnsureDatabaseReady::class, $sorted, true);
    $authIndex = array_search(Authenticate::class, $sorted, true);

    expect($gateIndex)->not->toBeFalse()
        ->and($authIndex)->not->toBeFalse()
        ->and($gateIndex)->toBeLessThan($authIndex);
});

// -----------------------------------------------------------------------
// MobileWelcomeScreen
// -----------------------------------------------------------------------

it('renders the welcome screen on a genuine fresh install with both CTAs', function (): void {
    expect(User::query()->count())->toBe(0);

    Livewire::test(MobileWelcomeScreen::class)
        ->assertNoRedirect()
        ->assertOk()
        ->assertSee('Welcome to beatrax')
        ->assertSee('Create account')
        ->assertSee('Import from another device')
        ->assertSeeHtml(route('signup'))
        // Phase 15 import-join (Task 5): the CTA now targets the
        // fresh-device local-identity bootstrap (mobile.import), which
        // itself redirects into mobile.pair?mode=import once provisioned
        // — not mobile.pair directly.
        ->assertSeeHtml(route('mobile.import'));
});

it('redirects an already-set-up device (a user exists) to the dashboard', function (): void {
    mobileWelcomeGateUser('already-set-up');

    Livewire::test(MobileWelcomeScreen::class)
        ->assertRedirect(route('dashboard'));
});

// -----------------------------------------------------------------------
// /signup regression (FirstUserOnlyMiddleware — existing behavior)
// -----------------------------------------------------------------------

it('keeps /signup reachable at 0 users', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->get(route('signup'))
        ->assertOk();
});

it('404s /signup once a user exists', function (): void {
    mobileWelcomeGateUser('signup-closed');

    $this->get(route('signup'))
        ->assertNotFound();
});
