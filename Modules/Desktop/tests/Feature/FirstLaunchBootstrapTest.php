<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
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

it('lets requests through when no migrations are pending', function (): void {
    $this->app['router']
        ->middleware(['web', EnsureDatabaseReady::class])
        ->get('/__test/gated-ok', static fn () => 'GATED-OK');

    // RefreshDatabase has already migrated.
    $this->get('/__test/gated-ok')
        ->assertOk()
        ->assertSee('GATED-OK');
});

it('exempts the setup route itself from the gate', function (): void {
    // Drop the migrations repository so the gate is "pending".
    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get(route('desktop.setup'))
        ->assertOk();
});

it('renders the welcome screen on a fresh install with no users', function (): void {
    $this->get(route('desktop.welcome'))
        ->assertOk()
        ->assertSee('Welcome to diederik')
        ->assertSee('Get started');
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

    $spy = new class(
        $real->getRepository(),
        $this->app->make(ConnectionResolverInterface::class),
        $real->getFilesystem(),
        $this->app->make(Dispatcher::class),
        $real,
        $fake,
    ) extends Migrator
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
