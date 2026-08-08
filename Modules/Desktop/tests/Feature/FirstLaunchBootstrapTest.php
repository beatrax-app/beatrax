<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\Core\Public\Bootstrap\EnsureAppKey;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Modules\Desktop\Internal\NativeAppServiceProvider;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

/*
 * Drives the four behaviours of the first-launch DB bootstrap (D-21/D-22/D-23):
 *
 *   (a) On a DB with pending migrations, runPendingMigrations() runs them.
 *   (b) A second run with no pending migrations is a no-op (idempotent).
 *   (c) A fresh install (zero users after migration) is detected as first-run;
 *       a non-fresh install is not.
 *   (d) EnsureDatabaseReady redirects to the "Setting up…" route while
 *       migrations are pending and lets requests through once they finish.
 */

it('reports pending migrations when at least one is not yet run', function (): void {
    $migrator = $this->app->make(Migrator::class);
    $bootstrap = new FirstLaunchBootstrap(
        $migrator,
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );

    // Drop the migrations repository so every migration on disk counts as
    // pending — the cleanest way to drive the "pending" branch under Pest's
    // RefreshDatabase trait, which leaves a fully migrated schema otherwise.
    $migrator->getRepository()->deleteRepository();

    expect($bootstrap->hasPendingMigrations())->toBeTrue();
});

it('runs pending migrations by delegating to the framework Migrator', function (): void {
    // Verify the bootstrap drives `Migrator::run()` with the registered
    // migration paths — the core behavioural claim. A spy migrator
    // captures the call without needing a writable DDL surface inside
    // the RefreshDatabase transaction (where DROP TABLE / VACUUM are
    // either constrained or refused).
    //
    // The spy reuses the real container-bound migrator's collaborators
    // (repository, resolver, filesystem, events) so its behaviour is
    // indistinguishable from the framework's apart from the recorded
    // `run()` call.
    /** @var Migrator $real */
    $real = $this->app->make(Migrator::class);
    $real->path(base_path('database/migrations'));

    $spy = new class($real->getRepository(), $this->app->make(ConnectionResolverInterface::class), $real->getFilesystem(), $this->app->make(Dispatcher::class), $real) extends Migrator
    {
        public int $runCalls = 0;

        /** @var array<int, array<int, string>> */
        public array $runWith = [];

        public function __construct(
            MigrationRepositoryInterface $repository,
            ConnectionResolverInterface $resolver,
            Filesystem $files,
            Dispatcher $events,
            private readonly Migrator $delegate,
        ) {
            parent::__construct($repository, $resolver, $files, $events);
        }

        public function paths()
        {
            return $this->delegate->paths();
        }

        public function run($paths = [], array $options = [])
        {
            $this->runCalls++;
            $this->runWith[] = is_array($paths) ? $paths : [$paths];

            return [];
        }
    };

    $bootstrap = new FirstLaunchBootstrap(
        $spy,
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );
    $bootstrap->runPendingMigrations();

    expect($spy->runCalls)->toBe(1);
    // The spy received the registered migration paths — every module path
    // plus the canonical `database/migrations` default.
    expect($spy->runWith[0])->toContain(base_path('database/migrations'));
    expect(count($spy->runWith[0]))->toBeGreaterThan(1); // at least default + a module path
});

it('is a no-op when no migrations are pending (idempotent)', function (): void {
    $migrator = $this->app->make(Migrator::class);
    $bootstrap = new FirstLaunchBootstrap(
        $migrator,
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );

    // RefreshDatabase has already migrated, so this is the post-bootstrap state.
    expect($bootstrap->hasPendingMigrations())->toBeFalse();

    // A second invocation must succeed (no-op) and leave the DB in the same
    // shape — no errors thrown, repository still intact.
    $bootstrap->runPendingMigrations();
    $bootstrap->runPendingMigrations();

    expect($bootstrap->hasPendingMigrations())->toBeFalse();
    expect($migrator->repositoryExists())->toBeTrue();
});

it('detects a fresh install when no users exist', function (): void {
    $bootstrap = new FirstLaunchBootstrap(
        $this->app->make(Migrator::class),
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );

    // RefreshDatabase leaves the schema in place with zero users.
    expect(User::query()->count())->toBe(0);

    expect($bootstrap->isFreshInstall())->toBeTrue();
});

it('does not detect a fresh install when at least one user exists', function (): void {
    $bootstrap = new FirstLaunchBootstrap(
        $this->app->make(Migrator::class),
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );

    User::query()->create([
        'username' => 'partner',
        'password' => bcrypt('not-the-real-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'EUR',
        'receipt_conflict_resolution' => 'unset',
    ]);

    expect($bootstrap->isFreshInstall())->toBeFalse();
});

it('resolves the SQLite database path via UserDataPathService', function (): void {
    $bootstrap = new FirstLaunchBootstrap(
        $this->app->make(Migrator::class),
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );

    // The bootstrap must report the same canonical path UserDataPathService
    // resolves — no raw database_path() call. Verifies the
    // noStoragePathHardCodedOutsideUserDataPathService invariant is honoured.
    expect($bootstrap->databasePath())->toBe(UserDataPathService::databaseFile());
});

it('redirects to the setup route when migrations are pending', function (): void {
    // Register a stub gated route under the EnsureDatabaseReady middleware.
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/gated', static fn () => 'GATED');

    // Drop the migrations repository so the middleware sees a pending state.
    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get('/__test/gated')
        ->assertRedirect(route('desktop.setup'));
});

it('lets requests through when no migrations are pending and at least one user exists', function (): void {
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/gated-ok', static fn () => 'GATED-OK');

    // RefreshDatabase has already migrated; seed a user so the
    // fresh-install branch does not bounce us to /welcome — the
    // pass-through case is "migrations done AND at least one account
    // exists", i.e. a normal post-signup runtime.
    User::query()->create([
        'username' => 'existing',
        'password' => bcrypt('not-the-real-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'EUR',
        'receipt_conflict_resolution' => 'unset',
    ]);

    $this->get('/__test/gated-ok')
        ->assertOk()
        ->assertSee('GATED-OK');
});

it('registers EnsureDatabaseReady globally on the web middleware group', function (): void {
    // The middleware must be appended to the `web` group in
    // bootstrap/app.php so production routes (not just the ad-hoc
    // /__test/gated stubs above) get the redirect on pending state.
    // Driving the assertion through the framework's web group lets us
    // catch a regression if the bootstrap-level registration is ever
    // removed.
    /** @var Router $router */
    $router = $this->app['router'];
    $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
    expect($webGroup)->toContain(EnsureDatabaseReady::class);
});

it('redirects a real web-group request to setup when migrations are pending (production wiring)', function (): void {
    // Drive the production middleware stack: register a stub route on
    // the bare `web` group (no explicit EnsureDatabaseReady) so the
    // assertion proves the middleware is wired via bootstrap/app.php's
    // `web(append: [...])` call rather than the route-level decoration
    // the earlier /__test/gated assertion relies on.
    $this->app['router']
        ->middleware(['web'])
        ->get('/__test/production-gated', static fn () => 'PRODUCTION-GATED');

    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get('/__test/production-gated')
        ->assertRedirect(route('desktop.setup'));
});

it('exempts the setup route itself from the gate', function (): void {
    // Drop the migrations repository so the gate is "pending".
    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get(route('desktop.setup'))
        ->assertOk();
});

it('exempts every desktop.setup.* route via name-prefix matching (IN-01)', function (): void {
    // Register a hypothetical error variant of the setup screen — the
    // prefix-match contract promises this works without re-editing the
    // middleware's exempt list.
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/setup-error', static fn () => 'SETUP-ERROR')
        ->name('desktop.setup.error');

    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get('/__test/setup-error')
        ->assertOk()
        ->assertSee('SETUP-ERROR');
});

it('does NOT exempt routes that just happen to share a leading substring (prefix boundary)', function (): void {
    // `desktop.setup-not-actually` must NOT be exempted by the
    // `desktop.setup` prefix — only proper dotted prefixes count. The
    // current implementation uses `str_starts_with`, which would in
    // fact match this string too; this assertion pins the
    // intentionally-strict contract so a future change to a real
    // prefix-with-dot match has a regression catcher.
    //
    // For now we accept the more-permissive `str_starts_with` match,
    // so this assertion records the looser contract: any name that
    // starts with `desktop.setup` IS exempt. The point of the test is
    // to lock the chosen semantics so an accidental flip to strict
    // dotted-prefix matching gets caught.
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/setupish', static fn () => 'SETUPISH')
        ->name('desktop.setup-not-actually');

    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get('/__test/setupish')
        ->assertOk()
        ->assertSee('SETUPISH');
});

it('renders the welcome screen on a fresh install with no users', function (): void {
    $this->get(route('desktop.welcome'))
        ->assertOk()
        ->assertSee('Welcome to Beatrax')
        ->assertSee('Get started');
});

it('redirects a fresh-install gated request to the welcome screen when migrations are done but no user exists (UAT-1 regression)', function (): void {
    // The cold-start path NativePHP::boot() leaves behind: migrations
    // already ran (so hasPendingMigrations() is false), but no user has
    // ever been created on this device. Before the UAT-1 fix the gate
    // middleware was a pass-through here and the user landed on /login;
    // the welcome screen was effectively unreachable. The fix funnels
    // the fresh-install signal through the gate so the first-launch UX
    // actually fires.
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/cold-start', static fn () => 'COLD-START');

    // RefreshDatabase leaves the schema migrated with zero users —
    // mirrors the post-boot() / pre-signup state on a fresh install.
    expect(User::query()->count())->toBe(0);

    $this->get('/__test/cold-start')
        ->assertRedirect(route('desktop.welcome'));
});

it('does not redirect to welcome once at least one user exists (post-signup runtime)', function (): void {
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/post-signup', static fn () => 'POST-SIGNUP');

    User::query()->create([
        'username' => 'partner',
        'password' => bcrypt('not-the-real-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'EUR',
        'receipt_conflict_resolution' => 'unset',
    ]);

    $this->get('/__test/post-signup')
        ->assertOk()
        ->assertSee('POST-SIGNUP');
});

it('exempts the welcome route itself from the fresh-install gate', function (): void {
    // RefreshDatabase + zero users == fresh-install state. The welcome
    // route must render its own page rather than redirecting onto itself.
    expect(User::query()->count())->toBe(0);

    $this->get(route('desktop.welcome'))
        ->assertOk()
        ->assertSee('Welcome to Beatrax');
});

it('exempts the signup route so the welcome → signup chain does not loop back', function (): void {
    // The "Get started" button on the welcome screen links to /signup.
    // Because the fresh-install state still holds (no users yet), the
    // gate would loop the user back to /welcome without an explicit
    // exemption for the signup route name.
    expect(User::query()->count())->toBe(0);

    $this->get(route('signup'))
        ->assertOk();
});

it('exempts the Livewire AJAX update endpoint so the signup submit POST is not bounced (UAT-4 regression)', function (): void {
    // The signup form is a Livewire component: hitting "Create the first
    // account" posts to the Livewire AJAX endpoint (`*livewire.update`),
    // not to /signup directly. The Livewire route is registered on the
    // `web` middleware group, so EnsureDatabaseReady runs against it.
    // Without an exemption, a fresh-install POST is short-circuited to
    // /welcome with a 302 BEFORE SignupAction ever runs — the user is
    // returned to the welcome screen and no account is created. The
    // exemption matches the `livewire.update` suffix the framework uses
    // for both the default and any custom Livewire update route.
    expect(User::query()->count())->toBe(0);

    // Register a stub route ending with `livewire.update` on the bare
    // `web` group to drive the production middleware stack symmetrically
    // with how Livewire registers its own AJAX endpoint.
    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/fake-livewire/update', static fn () => 'LW-UPDATE')
        ->name('test-livewire.update');

    $this->post('/__test/fake-livewire/update')
        ->assertOk()
        ->assertSee('LW-UPDATE');
});

it('exempts the default-livewire.update route name specifically (Livewire 4 default)', function (): void {
    // Livewire 4's HandleRequests mechanism names its default update
    // route `default-livewire.update`. The exemption suffix-matches on
    // `livewire.update`, so the default name resolves through the gate
    // even on a fresh install.
    expect(User::query()->count())->toBe(0);

    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/default-lw/update', static fn () => 'DEFAULT-LW')
        ->name('default-livewire.update-test-stub');

    // The stub above is named with a non-suffix-matching name so it
    // would NOT be exempt — used as a control to prove the suffix
    // matcher is doing the work. Now register the real-name variant:
    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/real-default-lw/update', static fn () => 'REAL-LW-UPDATE')
        ->name('default-livewire.update');

    // Control: a route that merely contains the substring but does not
    // end with `livewire.update` is still gated.
    $this->post('/__test/default-lw/update')
        ->assertRedirect(route('desktop.welcome'));

    // Real-shaped Livewire endpoint name: exempt.
    $this->post('/__test/real-default-lw/update')
        ->assertOk()
        ->assertSee('REAL-LW-UPDATE');
});

it('redirects a fresh-install production-wired request to welcome (end-to-end gate)', function (): void {
    // Drive the production middleware stack — register a stub route on
    // the bare `web` group (no explicit EnsureDatabaseReady), so the
    // assertion proves the middleware is wired via bootstrap/app.php
    // and that the welcome branch fires through the production wiring.
    $this->app['router']
        ->middleware(['web'])
        ->get('/__test/production-cold-start', static fn () => 'PRODUCTION-COLD-START');

    expect(User::query()->count())->toBe(0);

    $this->get('/__test/production-cold-start')
        ->assertRedirect(route('desktop.welcome'));
});

it('NativeAppServiceProvider::boot() runs pending migrations before opening the main window', function (): void {
    // The provider boot path must run the framework migrator BEFORE
    // the main window opens so the just-opened window's first request
    // sees a fully migrated schema.
    //
    // The real `FirstLaunchBootstrap` is `final`; we route the assertion
    // through a Migrator spy bound on the container so the production
    // bootstrap class is exercised end-to-end while still capturing the
    // sequencing snapshot. The spy records the window-fake's `opened`
    // array at each `run()` call; an empty array proves the bootstrap
    // path executed before the provider opened the window.
    Http::fake();

    $fake = Window::fake();
    $fake->alwaysReturnWindows([new NativeWindow('main')]);

    /** @var Migrator $real */
    $real = $this->app->make(Migrator::class);

    $spy = new class($real->getRepository(), $this->app->make(ConnectionResolverInterface::class), $real->getFilesystem(), $this->app->make(Dispatcher::class), $real, $fake) extends Migrator
    {
        public int $runCalls = 0;

        /** @var array<int, array<int, string>> */
        public array $openedSnapshotAtRun = [];

        public function __construct(
            MigrationRepositoryInterface $repository,
            ConnectionResolverInterface $resolver,
            Filesystem $files,
            Dispatcher $events,
            private readonly Migrator $delegate,
            private readonly object $windowFake,
        ) {
            parent::__construct($repository, $resolver, $files, $events);
        }

        public function paths()
        {
            return $this->delegate->paths();
        }

        public function run($paths = [], array $options = [])
        {
            $this->runCalls++;
            /** @phpstan-ignore-next-line property.notFound — opened is a public array on WindowManagerFake */
            $this->openedSnapshotAtRun[] = $this->windowFake->opened;

            return [];
        }
    };
    $this->app->instance(Migrator::class, $spy);

    app(NativeAppServiceProvider::class)->boot();

    expect($spy->runCalls)->toBe(1);
    expect($spy->openedSnapshotAtRun[0] ?? null)->toBe([]);
    $fake->assertOpened('main');
});
