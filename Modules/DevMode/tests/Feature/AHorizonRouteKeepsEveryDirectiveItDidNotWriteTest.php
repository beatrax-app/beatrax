<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\HorizonFramePage;
use Modules\DevMode\Internal\Http\Middleware\HorizonFrameAncestors;

// Route middleware runs inside the globally appended one that owns the base
// policy, so a route setting the whole header wins it. /dev/horizon shipped a
// frame rule and nothing else — and the developer flag is self-settable, so
// this is not an administrator-only surface.

function framePolicyUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

// loadRoutesFrom() fires once at boot, before any test can flip
// config('app.dev_mode'), so the conditional registration in the provider's
// boot() has to be replayed by hand for the flag change to mean anything.
function framePolicyRegisterHorizonRoute(): void
{
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('app.dev_mode', true);

    /** @var Router $router */
    $router = app(Router::class);

    Route::setRoutes(new RouteCollection);

    require base_path('Modules/DevMode/Routes/web.php');

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

    $router->getRoutes()->refreshNameLookups();
}

it('asserts the Horizon package class IS present, which the route registration below needs', function (): void {
    expect(class_exists(HorizonServiceProvider::class))->toBeTrue();
});

it('serves /dev/horizon with the whole base policy, not only the frame rule it wrote', function (): void {
    framePolicyRegisterHorizonRoute();

    $response = test()->actingAs(framePolicyUser('csp-horizon-base'))->get('/dev/horizon');

    $response->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'")
        ->and($csp)->toContain("connect-src 'self'");
});

it('lets the route narrow the one directive it is allowed to narrow', function (): void {
    framePolicyRegisterHorizonRoute();

    $response = test()->actingAs(framePolicyUser('csp-horizon-frame'))->get('/dev/horizon');

    $response->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->not->toContain("frame-ancestors 'none'");
});

it('withdraws X-Frame-Options only against a policy that carries a frame rule', function (): void {
    // The two headers conflict and browsers disagree on which wins, so one of
    // them stands down. Dropping it against a policy with no frame-ancestors
    // left /dev/horizon with no frame policy at all.
    framePolicyRegisterHorizonRoute();

    $response = test()->actingAs(framePolicyUser('csp-horizon-xfo'))->get('/dev/horizon');

    $response->assertOk();

    expect($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors');
});

it('serves a dev route that declares nothing with the base policy', function (): void {
    // The control. It passes whether or not the merge admits anything at all,
    // so a base policy missing from the assertions above is the merge and not
    // a route that never reached this middleware.
    framePolicyRegisterHorizonRoute();

    $response = test()->actingAs(framePolicyUser('csp-dev-overview'))->get('/dev');

    $response->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'");
});
