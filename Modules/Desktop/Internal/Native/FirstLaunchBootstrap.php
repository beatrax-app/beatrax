<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * First-launch DB bootstrap (D-21 / D-22 / D-23).
 *
 * Runs the framework migration runner once per launch — idempotent when
 * no migrations are pending, which lets later auto-update plans (Phase 18)
 * ship new migrations that absorb cleanly on the next boot.
 *
 * The shipped bundle holds its SQLite file under the
 * `UserDataPathService`-resolved path (NATIVEPHP_STORAGE_PATH-rooted in
 * the packaged build, project-rooted under Herd). The bootstrap never
 * calls `database_path()` / `storage_path()` / `base_path()` directly —
 * the `noStoragePathHardCodedOutsideUserDataPathService` arch invariant
 * forbids it.
 *
 * Fresh-install detection (D-22) reports whether any user exists after
 * migrations have run — a zero-user post-migration state is the signal
 * for the desktop module's welcome screen to short-circuit into `/signup`.
 *
 * No global helpers / facade calls — `Migrator` and `UserDataPathService`
 * arrive through the constructor.
 */
final class FirstLaunchBootstrap
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly UserDataPathService $paths,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The canonical SQLite path the bundled app reads + writes.
     *
     * Routed through `UserDataPathService` so the packaged build
     * (NATIVEPHP_STORAGE_PATH set) and Herd development (env unset)
     * both resolve correctly.
     */
    public function databasePath(): string
    {
        return $this->paths->databasePath();
    }

    /**
     * True when at least one migration on disk has not been recorded
     * in the `migrations` table. When the repository itself does not
     * yet exist (a brand-new SQLite file) every migration counts as
     * pending — the migrator creates the repository on its first run.
     */
    public function hasPendingMigrations(): bool
    {
        if (! $this->migrator->repositoryExists()) {
            // Repository absent → every migration on disk is pending,
            // provided any migration files actually exist.
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

    /**
     * Run every pending migration. Idempotent — when nothing is pending
     * the migrator's own `run()` is a no-op. When the repository does
     * not yet exist it is created first; the migrator can then record
     * the first run.
     */
    public function runPendingMigrations(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        $this->migrator->run($this->migrationPaths());
    }

    /**
     * Fresh-install signal (D-22). After migrations have run, an empty
     * `users` table marks the first launch on this device — the welcome
     * screen renders, then the user is dropped onto `/signup`.
     */
    public function isFreshInstall(): bool
    {
        return $this->db->connection()->table('users')->count() === 0;
    }

    /**
     * @return string[] absolute paths to every migration `.php` file
     *                  the framework knows about
     */
    private function discoverMigrationFiles(): array
    {
        $files = $this->migrator->getMigrationFiles($this->migrationPaths());

        return array_values($files);
    }

    /**
     * @return string[] every migration source path the framework knows
     *                  about — module-registered paths plus the
     *                  canonical project `database/migrations` path
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
