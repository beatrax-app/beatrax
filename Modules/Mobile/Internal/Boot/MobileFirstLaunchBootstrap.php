<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Services\UserDataPathService;
use Psr\Log\LoggerInterface;

final readonly class MobileFirstLaunchBootstrap
{
    public function __construct(
        private Migrator $migrator,
        private UserDataPathService $paths,
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {}

    // Routed through UserDataPathService so this stays the single
    // canonical-path authority.
    public function databasePath(): string
    {
        return $this->paths->databasePath();
    }

    // When the migrations repository does not yet exist (a brand-new
    // on-device SQLite file), every migration counts as pending - the
    // migrator creates the repository on its first run.
    public function hasPendingMigrations(): bool
    {
        if (! $this->migrator->repositoryExists()) {
            return $this->discoverMigrationFiles() !== [];
        }

        $ran = $this->migrator->getRepository()->getRan();
        $files = $this->discoverMigrationFiles();

        foreach ($files as $file) {
            $name = $this->migrator->getMigrationName($file);
            if (! in_array($name, $ran, true)) {
                return true;
            }
        }

        return false;
    }

    // Every path a NativePHP plugin hands to loadViewsFrom(). The bundler
    // strips their whole `resources/` tree, so each one has to be recreated.
    private const array PLUGIN_VIEW_PATHS = ['resources/views', 'resources/jump/views'];

    // The bundler strips every plugin's `resources/` directory, but their
    // service providers still call loadViewsFrom() on those paths.
    // The view finder throws DirectoryNotFoundException on a path that is
    // not there, so re-creating the empty directories restores the contract.
    public function ensurePluginViewPaths(string $basePath): void
    {
        $candidates = glob($basePath.'/vendor/nativephp/*/src', GLOB_ONLYDIR);

        foreach ($candidates === false ? [] : $candidates as $src) {
            foreach (self::PLUGIN_VIEW_PATHS as $relative) {
                $views = dirname($src).'/'.$relative;

                if (! is_dir($views)) {
                    @mkdir($views, 0755, true);
                }
            }
        }
    }

    // Idempotent - the migrator's own run() is a no-op when nothing is
    // pending. Creates the migrations repository first if it does not
    // yet exist so the migrator can record the first run.
    public function runPendingMigrations(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        try {
            $this->migrator->run($this->migrationPaths());
        } finally {
            // SQLite reports supportsSchemaTransactions() false, so a run that
            // throws leaves what it applied behind and no retry undoes it.
            // Recorded, because a half-built schema otherwise opens the app
            // and looks healthy until the first tap answers 500.
            $this->recordSchemaCompletion();
        }
    }

    // A marker that could not be written is the same failure one launch later,
    // and the only place that blind spot is named: this process refuses from
    // memory, and nothing else would ever say the next one cannot.
    private function recordSchemaCompletion(): void
    {
        if (! $this->hasPendingMigrations()) {
            SchemaCompletionMarker::clear();

            return;
        }

        if (! SchemaCompletionMarker::raise()) {
            $this->logger->warning(
                'MobileFirstLaunchBootstrap: the schema-incomplete marker could not be written — this launch refuses from memory, the next one will open over a half-built schema.',
                ['marker_directory_writable' => is_writable(dirname(SchemaCompletionMarker::path()))],
            );
        }
    }

    // After migrations have run, an empty `users` table marks the first
    // launch of this on-device install.
    public function isFreshInstall(): bool
    {
        return $this->db->connection()->table('users')->count() === 0;
    }

    /**
     * @return string[] absolute paths to every migration `.php` file the
     *                  framework knows about
     */
    private function discoverMigrationFiles(): array
    {
        $files = $this->migrator->getMigrationFiles($this->migrationPaths());

        return array_values($files);
    }

    /**
     * @return string[] every migration source path the framework knows
     *                  about — module-registered paths plus the canonical
     *                  project `database/migrations` path
     */
    private function migrationPaths(): array
    {
        $paths = $this->migrator->paths();

        $defaultPath = $this->paths::migrationsPath();
        if (is_dir($defaultPath) && ! in_array($defaultPath, $paths, true)) {
            $paths[] = $defaultPath;
        }

        return $paths;
    }
}
