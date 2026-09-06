<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Http\Livewire\HorizonFramePage;
use Modules\DevMode\Internal\Http\Middleware\HorizonFrameAncestors;

// Horizon is require-dev, so the class signal is absent from a shipped
// `composer install --no-dev` bundle and the route never registers there.
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

// loadRoutesFrom() fires once at boot, before any test can flip
// config('app.dev_mode'), so the conditional registration has to be replayed
// by hand for the flag change to mean anything.
function horizonGatingReloadRoutes(): void
{
    /** @var Router $router */
    $router = app(Router::class);

    Route::setRoutes(new RouteCollection);

    // The routes file registers every /dev/* route except the Horizon iframe,
    // which lives in the provider's boot().
    require base_path('Modules/DevMode/Routes/web.php');

    /** @var Repository $config */
    $config = app(Repository::class);
    if ($config->get('app.dev_mode') === true && class_exists(HorizonServiceProvider::class)) {
        $router->group(
            [
                'middleware' => ['web', 'auth', 'ensureDeveloperMode'],
                'prefix' => '/dev',
            ],
            static function (Router $router): void {
                $router->get('/horizon', HorizonFramePage::class)
                    ->middleware(HorizonFrameAncestors::class)
                    ->name('dev.horizon');
            },
        );
    }

    // Route::has() reads RouteCollection's separate name-lookup table, not the
    // live route list, so without this it answers from stale state.
    $router->getRoutes()->refreshNameLookups();
}

it('asserts that the Horizon package class IS present in the test environment (precondition for the gating tests below)', function (): void {
    // Every test below varies only the env flag, which is meaningless unless
    // the class signal is already satisfied here.
    expect(class_exists(HorizonServiceProvider::class))
        ->toBeTrue('Horizon package is expected to be installed in the dev/test environment.');
});

it('does NOT register the dev.horizon route when config("app.dev_mode") is null (the shipped-build default)', function (): void {
    horizonGatingSetDevModeFlag(false);

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

    // DOM-absent rather than nav-disabled: the other sidebar entries degrade
    // to disabled, this one disappears.
    expect(str_contains($html, '>Horizon<'))
        ->toBeFalse('Horizon nav item should be DOM-absent when dev.horizon is not registered.');
    expect(str_contains($html, 'href="/dev/horizon"'))
        ->toBeFalse('Sidebar should not link to /dev/horizon when the route is not registered.');
});

it('emits Content-Security-Policy: frame-ancestors self on GET /dev/horizon (clickjacking guard)', function (): void {
    horizonGatingSetDevModeFlag(true);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-csp');

    $response = $this->actingAs($user)->get('/dev/horizon');

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self'");
});

it('renders the Horizon iframe with sandbox + referrerpolicy attributes (clickjacking + referer leak guards)', function (): void {
    horizonGatingSetDevModeFlag(true);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-iframe-attrs');

    $response = $this->actingAs($user)->get('/dev/horizon');

    $response->assertOk();
    $response->assertSee('sandbox="allow-same-origin allow-scripts allow-forms"', escape: false);
    $response->assertSee('referrerpolicy="same-origin"', escape: false);
});

it('renders the Horizon sidebar nav item WITHOUT nav-disabled when the dev.horizon route IS registered', function (): void {
    horizonGatingSetDevModeFlag(true);
    horizonGatingReloadRoutes();

    $user = horizonGatingUser('hz-sidebar-on');

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    $matches = PatternScan::all('#<a\s+href="[^"]*"\s+class="side-item([^"]*)"[^>]*>[\s\S]*?Horizon[\s\S]*?</a>#', $html);

    expect($matches[0])->not->toBeEmpty('Horizon sidebar entry should render when dev.horizon is registered');
    foreach ($matches[1] as $classes) {
        expect(str_contains($classes, 'nav-disabled'))->toBeFalse('Horizon nav item should not be nav-disabled when its route is registered');
    }
});
