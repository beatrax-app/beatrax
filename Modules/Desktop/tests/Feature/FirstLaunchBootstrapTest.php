<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;

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
    $bootstrap = new FirstLaunchBootstrap($migrator, $this->app->make(UserDataPathService::class));

    // Drop the migrations repository so every migration on disk counts as
    // pending — the cleanest way to drive the "pending" branch under Pest's
    // RefreshDatabase trait, which leaves a fully migrated schema otherwise.
    $migrator->getRepository()->deleteRepository();

    expect($bootstrap->hasPendingMigrations())->toBeTrue();
});

it('runs pending migrations when invoked', function (): void {
    $migrator = $this->app->make(Migrator::class);
    $bootstrap = new FirstLaunchBootstrap($migrator, $this->app->make(UserDataPathService::class));

    // Drop the migrations repository → every migration is pending.
    $migrator->getRepository()->deleteRepository();
    expect($migrator->repositoryExists())->toBeFalse();

    $bootstrap->runPendingMigrations();

    expect($migrator->repositoryExists())->toBeTrue();
    expect($bootstrap->hasPendingMigrations())->toBeFalse();
});

it('is a no-op when no migrations are pending (idempotent)', function (): void {
    $migrator = $this->app->make(Migrator::class);
    $bootstrap = new FirstLaunchBootstrap($migrator, $this->app->make(UserDataPathService::class));

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
    );

    // RefreshDatabase leaves the schema in place with zero users.
    expect(User::query()->count())->toBe(0);

    expect($bootstrap->isFreshInstall())->toBeTrue();
});

it('does not detect a fresh install when at least one user exists', function (): void {
    $bootstrap = new FirstLaunchBootstrap(
        $this->app->make(Migrator::class),
        $this->app->make(UserDataPathService::class),
    );

    User::query()->create([
        'username' => 'partner',
        'password' => bcrypt('not-the-real-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'EUR',
        'receipt_conflict_resolution' => 'manual_review',
    ]);

    expect($bootstrap->isFreshInstall())->toBeFalse();
});

it('resolves the SQLite database path via UserDataPathService', function (): void {
    $bootstrap = new FirstLaunchBootstrap(
        $this->app->make(Migrator::class),
        $this->app->make(UserDataPathService::class),
    );

    // The bootstrap must report the same canonical path UserDataPathService
    // resolves — no raw database_path() call. Verifies the
    // noStoragePathHardCodedOutsideUserDataPathService invariant is honoured.
    expect($bootstrap->databasePath())->toBe(UserDataPathService::databaseFile());
});

it('redirects to the setup route when migrations are pending', function (): void {
    // Register a stub gated route under the EnsureDatabaseReady middleware.
    $this->app['router']
        ->middleware(['web', \Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady::class])
        ->get('/__test/gated', static fn () => 'GATED');

    // Drop the migrations repository so the middleware sees a pending state.
    $this->app->make(Migrator::class)->getRepository()->deleteRepository();

    $this->get('/__test/gated')
        ->assertRedirect(route('desktop.setup'));
});

it('lets requests through when no migrations are pending', function (): void {
    $this->app['router']
        ->middleware(['web', \Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady::class])
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
