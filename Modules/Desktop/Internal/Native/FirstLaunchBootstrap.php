<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Bootstrap\EnsureAppKey;
use Modules\Core\Public\Services\UserDataPathService;

// Migrations run before APP_KEY is minted, so any table an encrypt-on-store step
// needs already exists the first time the encrypter is used. Both are idempotent.
final readonly class FirstLaunchBootstrap
{
    public function __construct(
        private Migrator $migrator,
        private UserDataPathService $paths,
        private DatabaseManager $db,
        private EnsureAppKey $ensureAppKey,
    ) {}

    public function databasePath(): string
    {
        return $this->paths->databasePath();
    }

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

    public function runPendingMigrations(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        $this->migrator->run($this->migrationPaths());

        $this->ensureAppKey->run();
    }

    public function isFreshInstall(): bool
    {
        return $this->db->connection()->table('users')->count() === 0;
    }

    /**
     * @return string[]
     */
    private function discoverMigrationFiles(): array
    {
        $files = $this->migrator->getMigrationFiles($this->migrationPaths());

        return array_values($files);
    }

    /**
     * @return string[]
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
