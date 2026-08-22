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

it('reports pending migrations when at least one is not yet run', function (): void {
    $migrator = $this->app->make(Migrator::class);
    $bootstrap = new FirstLaunchBootstrap(
        $migrator,
        $this->app->make(UserDataPathService::class),
        $this->app->make(DatabaseManager::class),
        $this->app->make(EnsureAppKey::class),
    );

    // RefreshDatabase leaves a fully migrated schema, so dropping the migrations
    // repository is what makes every migration on disk count as pending.
    $migrator->getRepository()->deleteRepository();

    expect($bootstrap->hasPendingMigrations())->toBeTrue();
});

it('runs pending migrations by delegating to the framework Migrator', function (): void {
    // A spy migrator captures the `run()` call without needing a writable DDL
    // surface inside the RefreshDatabase transaction, where DROP TABLE and
    // VACUUM are constrained or refused. It reuses the real container-bound
    // migrator's collaborators so it behaves like the framework's otherwise.
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

    // The path must come from UserDataPathService rather than a raw
    // database_path() — the noStoragePathHardCodedOutsideUserDataPathService
    // invariant.
    expect($bootstrap->databasePath())->toBe(UserDataPathService::databaseFile());
});

it('redirects to the setup route when migrations are pending', function (): void {
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

    // Seed a user so the fresh-install branch does not bounce to /welcome: the
    // pass-through case is migrations done AND at least one account existing.
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
    // Appending to the `web` group in bootstrap/app.php is what makes production
    // routes gated, not just the route-level stubs the earlier tests decorate.
    /** @var Router $router */
    $router = $this->app['router'];
    $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
    expect($webGroup)->toContain(EnsureDatabaseReady::class);
});

it('redirects a real web-group request to setup when migrations are pending (production wiring)', function (): void {
    // A stub on the bare `web` group, with no route-level decoration, so the
    // redirect can only come from the bootstrap/app.php registration.
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

it('exempts every desktop.setup.* route via name-prefix matching', function (): void {
    // A hypothetical error variant of the setup screen: the prefix match is
    // meant to cover it without re-editing the middleware's exempt list.
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
    // A prefix ends on a dot. Without that, 'sw' matched any route named
    // sw-anything, and this one proves the boundary rather than pinning the
    // substring behaviour the test's own name always denied.
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/setupish', static fn () => 'SETUPISH')
        ->name('desktop.setup-not-actually');

    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get('/__test/setupish')->assertRedirect();
});

it('renders the welcome screen on a fresh install with no users', function (): void {
    $this->get(route('desktop.welcome'))
        ->assertOk()
        ->assertSee('Welcome to Beatrax')
        ->assertSee('Get started');
});

it('redirects a fresh-install gated request to the welcome screen when migrations are done but no user exists', function (): void {
    // After NativePHP::boot() the migrations have run but no user exists yet.
    // The gate used to pass this state through to /login, leaving the welcome
    // screen unreachable; the fresh-install signal now routes through the gate.
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/cold-start', static fn () => 'COLD-START');

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
    expect(User::query()->count())->toBe(0);

    $this->get(route('desktop.welcome'))
        ->assertOk()
        ->assertSee('Welcome to Beatrax');
});

it('exempts the signup route so the welcome → signup chain does not loop back', function (): void {
    // "Get started" links to /signup while the fresh-install state still holds,
    // so without an exemption the gate would loop the user back to /welcome.
    expect(User::query()->count())->toBe(0);

    $this->get(route('signup'))
        ->assertOk();
});

it('exempts the Livewire AJAX update endpoint so the signup submit POST is not bounced', function (): void {
    // The signup submit posts to Livewire's AJAX endpoint, not to /signup, and
    // that route sits on the `web` group. Without an exemption a fresh-install
    // POST is bounced to /welcome with a 302 before SignupAction runs, so no
    // account is ever created. The match is on the `livewire.update` suffix.
    expect(User::query()->count())->toBe(0);

    // Named the way Livewire names its own AJAX endpoint, on the bare `web` group.
    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/fake-livewire/update', static fn () => 'LW-UPDATE')
        ->name('test-livewire.update');

    $this->post('/__test/fake-livewire/update')
        ->assertOk()
        ->assertSee('LW-UPDATE');
});

it('exempts the default-livewire.update route name specifically (Livewire 4 default)', function (): void {
    // Livewire 4 names its default update route `default-livewire.update`; the
    // exemption suffix-matches, so that name resolves even on a fresh install.
    expect(User::query()->count())->toBe(0);

    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/default-lw/update', static fn () => 'DEFAULT-LW')
        ->name('default-livewire.update-test-stub');

    // The stub above is the control: its name does not end in the suffix, so it
    // stays gated. This second one carries the real Livewire endpoint name.
    $this->app['router']
        ->middleware(['web'])
        ->post('/__test/real-default-lw/update', static fn () => 'REAL-LW-UPDATE')
        ->name('default-livewire.update');

    $this->post('/__test/default-lw/update')
        ->assertRedirect(route('desktop.welcome'));

    $this->post('/__test/real-default-lw/update')
        ->assertOk()
        ->assertSee('REAL-LW-UPDATE');
});

it('redirects a fresh-install production-wired request to welcome (end-to-end gate)', function (): void {
    // Bare `web` group again, so the welcome branch is proven to fire through
    // the production wiring rather than a route-level decoration.
    $this->app['router']
        ->middleware(['web'])
        ->get('/__test/production-cold-start', static fn () => 'PRODUCTION-COLD-START');

    expect(User::query()->count())->toBe(0);

    $this->get('/__test/production-cold-start')
        ->assertRedirect(route('desktop.welcome'));
});

it('NativeAppServiceProvider::boot() runs pending migrations before opening the main window', function (): void {
    // Migrations must run before the main window opens, so the window's first
    // request sees a migrated schema. `FirstLaunchBootstrap` is final, so the
    // sequencing is captured by a Migrator spy that snapshots the window fake's
    // `opened` array at each run(): an empty array means the order held.
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
