<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Livewire\MobileWelcomeScreen;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;

// MobileEnsureDatabaseReady is registered on mobile-app/bootstrap/app.php, a
// separate Laravel root this process never boots, so the router-introspection
// assertion the desktop suite uses is unavailable — the wiring is pinned by a
// source-string assertion plus stub routes that run handle() for real.

function mobileWelcomeGateUser(string $suffix = ''): User
{
    return User::query()->create([
        'username' => 'mobile-welcome-gate-'.($suffix !== '' ? $suffix.'-' : '').bin2hex(random_bytes(4)),
        'password' => bcrypt('mobile-welcome-gate-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The shared harness boots the root app, which appends the DESKTOP gate to
// the same `web` group; it bounces a 0-user request to `desktop.welcome`
// first. Production keeps the two roots separate, so dropping it here
// corrects a harness artifact rather than changing behaviour.
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

it('exempts the mobile.welcome route itself so it does not loop', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    expect(User::query()->count())->toBe(0);

    $this->get(route('mobile.welcome'))
        ->assertOk()
        ->assertSee('Welcome to Beatrax');
});

it('exempts the signup route so the welcome to create-account chain does not loop', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->get(route('signup'))
        ->assertOk();
});

it('exempts routes named exactly "mobile.import" via prefix matching', function (): void {
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

    // The name ends with "...suffix-match", so a suffix matcher refuses it
    // where a loose substring match would have exempted it.
    $this->get('/__test/mobile-livewire-update-ish')
        ->assertRedirect(route('mobile.welcome'));
});

it('registers MobileEnsureDatabaseReady on the mobile-app root web middleware group', function (): void {
    $source = (string) file_get_contents(base_path('mobile-app/bootstrap/app.php'));

    expect($source)->toContain('use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;');
    expect($source)->toContain(MobileEnsureDatabaseReady::class);
    // Prepended, never appended: appending leaves the gate behind
    // Authenticate once the framework sorts by priority.
    expect($source)->toContain("\$middleware->prependToGroup('web', MobileEnsureDatabaseReady::class);");
    expect($source)->not->toContain("            MobileEnsureDatabaseReady::class,\n        ]);");
    // repo-root-only: the path resolves against the repo root, so from the
    // mobile-app root this looks for mobile-app/mobile-app/bootstrap/app.php.
})->group('repo-root-only');

it('runs the fresh-install gate before the auth middleware it exists to pre-empt', function (): void {
    // List position is not run position: appending put the gate at index 7
    // while SortedMiddleware hoisted Authenticate to index 5, so a 0-user
    // device threw AuthenticationException and landed on desktop `/login`.
    $router = $this->app['router'];

    // Mirror the one line of production wiring and let the framework's own
    // sorter decide, since this root never boots the mobile-app one.
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

it('renders the welcome screen on a genuine fresh install with both CTAs', function (): void {
    expect(User::query()->count())->toBe(0);

    Livewire::test(MobileWelcomeScreen::class)
        ->assertNoRedirect()
        ->assertOk()
        ->assertSee('Welcome to Beatrax')
        ->assertSee('Create account')
        ->assertSee('Import from another device')
        ->assertSeeHtml(route('signup'))
        // mobile.import, not mobile.pair: the bootstrap provisions a local
        // identity first, then redirects into mobile.pair?mode=import.
        ->assertSeeHtml(route('mobile.import'));
});

it('redirects an already-set-up device (a user exists) to the dashboard', function (): void {
    mobileWelcomeGateUser('already-set-up');

    Livewire::test(MobileWelcomeScreen::class)
        ->assertRedirect(route('dashboard'));
});

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
