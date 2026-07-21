<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class MobileFirstLaunchBootstrap
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly UserDataPathService $paths,
        private readonly DatabaseManager $db,
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

    // Idempotent - the migrator's own run() is a no-op when nothing is
    // pending. Creates the migrations repository first if it does not
    // yet exist so the migrator can record the first run.
    public function runPendingMigrations(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        $this->migrator->run($this->migrationPaths());
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
