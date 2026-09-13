<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

afterEach(fn () => SchemaCompletionMarker::clear());

function firstLaunchRecorder(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
        }
    };
}

/**
 * @param  callable(MockInterface): void  $arrange
 */
function firstLaunchBootstrap(callable $arrange, ?LoggerInterface $logger = null): MobileFirstLaunchBootstrap
{
    /** @var MockInterface&Migrator $migrator */
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('paths')->andReturn([])->byDefault();
    $migrator->shouldReceive('getMigrationFiles')->andReturn([])->byDefault();
    $migrator->shouldReceive('getMigrationName')->andReturnUsing(
        static fn (string $path): string => basename($path, '.php'),
    )->byDefault();

    $arrange($migrator);

    return new MobileFirstLaunchBootstrap(
        $migrator,
        app(UserDataPathService::class),
        app(DatabaseManager::class),
        $logger ?? firstLaunchRecorder(),
    );
}

it('counts every migration as pending when the repository is not there yet', function (): void {
    $bootstrap = firstLaunchBootstrap(function ($migrator): void {
        $migrator->shouldReceive('repositoryExists')->andReturnFalse();
        $migrator->shouldReceive('getMigrationFiles')->andReturn(['/x/2026_01_01_000000_create_users_table.php']);
    });

    expect($bootstrap->hasPendingMigrations())->toBeTrue();
});

it('counts none when the repository is not there and neither are any files', function (): void {
    $bootstrap = firstLaunchBootstrap(function ($migrator): void {
        $migrator->shouldReceive('repositoryExists')->andReturnFalse();
    });

    expect($bootstrap->hasPendingMigrations())->toBeFalse();
});

it('reads the ran list against the files on disk once the repository exists', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->andReturn(['2026_01_01_000000_create_users_table']);

    $bootstrap = firstLaunchBootstrap(function ($migrator) use ($repository): void {
        $migrator->shouldReceive('repositoryExists')->andReturnTrue();
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->andReturn([
            '/x/2026_01_01_000000_create_users_table.php',
            '/x/2026_02_02_000000_add_a_column.php',
        ]);
    });

    expect($bootstrap->hasPendingMigrations())->toBeTrue();
});

// The half-built schema this whole class exists to refuse. The marker is the
// only thing that carries the refusal to the next launch.
it('raises the marker when the run leaves work behind', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->andReturn([]);

    $bootstrap = firstLaunchBootstrap(function ($migrator) use ($repository): void {
        $migrator->shouldReceive('repositoryExists')->andReturnTrue();
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->andReturn(['/x/2026_01_01_000000_create_users_table.php']);
        $migrator->shouldReceive('run')->once();
    });

    $bootstrap->runPendingMigrations();

    expect(SchemaCompletionMarker::isRaised())->toBeTrue();
});

it('clears the marker when the run left nothing behind', function (): void {
    SchemaCompletionMarker::raise();

    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->andReturn(['2026_01_01_000000_create_users_table']);

    $bootstrap = firstLaunchBootstrap(function ($migrator) use ($repository): void {
        $migrator->shouldReceive('repositoryExists')->andReturnTrue();
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->andReturn(['/x/2026_01_01_000000_create_users_table.php']);
        $migrator->shouldReceive('run')->once();
    });

    $bootstrap->runPendingMigrations();

    expect(SchemaCompletionMarker::isRaised())->toBeFalse();
});

it('creates the repository before the first run, or nothing records it', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('createRepository')->once();
    $repository->shouldReceive('getRan')->andReturn([]);

    $bootstrap = firstLaunchBootstrap(function ($migrator) use ($repository): void {
        $migrator->shouldReceive('repositoryExists')->andReturnFalse()->once()->ordered();
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('run')->once();
        $migrator->shouldReceive('repositoryExists')->andReturnTrue();
    });

    $bootstrap->runPendingMigrations();
});

// The run failing is the case the marker is for, so the exception the caller
// catches has to be the migration's own. A throw from inside the finally would
// replace it, and mobile-app/bootstrap/app.php would log the wrong cause.
it('lets the migration failure reach the caller rather than the bookkeeping', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->andThrow(new RuntimeException('no such table: migrations'));

    $bootstrap = firstLaunchBootstrap(function ($migrator) use ($repository): void {
        $migrator->shouldReceive('repositoryExists')->andReturnTrue();
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->andReturn(['/x/2026_01_01_000000_create_users_table.php']);
        $migrator->shouldReceive('run')->andThrow(new RuntimeException('migration 2 of 190 failed'));
    });

    expect(fn () => $bootstrap->runPendingMigrations())
        ->toThrow(RuntimeException::class, 'migration 2 of 190 failed');
});

// And the refusal is still recorded, because the answer to "is the schema whole"
// was never obtained and unknown has to mean incomplete. mobile-app's bootstrap
// also raises it on any throw, so this is the second line rather than the only
// one — but it is the line that holds if this is ever called from anywhere else.
it('refuses the next launch even when it could not ask whether to', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->andThrow(new RuntimeException('no such table: migrations'));

    $bootstrap = firstLaunchBootstrap(function ($migrator) use ($repository): void {
        $migrator->shouldReceive('repositoryExists')->andReturnTrue();
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->andReturn(['/x/2026_01_01_000000_create_users_table.php']);
        $migrator->shouldReceive('run')->andThrow(new RuntimeException('migration 2 of 190 failed'));
    });

    try {
        $bootstrap->runPendingMigrations();
    } catch (RuntimeException) {
        // The assertion is what it left behind, not what it threw.
    }

    expect(SchemaCompletionMarker::isRaised())->toBeTrue();
});

it('recreates the view directories the bundler strips from every plugin', function (): void {
    $base = UserDataPathService::appPath('first-launch-plugin-probe');
    @mkdir($base.'/vendor/nativephp/mobile-scanner/src', 0755, true);

    firstLaunchBootstrap(fn ($migrator) => null)->ensurePluginViewPaths($base);

    expect(is_dir($base.'/vendor/nativephp/mobile-scanner/resources/views'))->toBeTrue()
        ->and(is_dir($base.'/vendor/nativephp/mobile-scanner/resources/jump/views'))->toBeTrue();

    exec('rm -rf '.escapeshellarg($base));
});

it('hands out the one canonical database path', function (): void {
    expect(firstLaunchBootstrap(fn ($migrator) => null)->databasePath())
        ->toBe(app(UserDataPathService::class)->databasePath());
});
