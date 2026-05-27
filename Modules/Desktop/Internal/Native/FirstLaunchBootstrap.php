<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Bootstrap\EnsureAppKey;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * First-launch DB bootstrap with chained idempotent steps.
 *
 * Runs the framework migration runner once per launch — idempotent when
 * no migrations are pending, which lets later migrations absorb cleanly
 * on the next boot — and then ensures the install's `APP_KEY` exists
 * via the sentinel-driven `EnsureAppKey` action. Both steps are safe to
 * re-invoke on every launch; the migrator's own `run()` is a no-op when
 * nothing is pending and `EnsureAppKey` short-circuits once its sentinel
 * file is present.
 *
 * The chain runs APP_KEY generation immediately AFTER migrations so the
 * shipped `cache_locks` and `oauth_secrets` tables exist before the
 * encrypter ever needs to write into them; any future "encrypt-on-store"
 * step further down the chain therefore boots with a valid key.
 *
 * The shipped bundle holds its SQLite file under the
 * `UserDataPathService`-resolved path (NATIVEPHP_STORAGE_PATH-rooted in
 * the packaged build, project-rooted under Herd). The bootstrap never
 * calls `database_path()` / `storage_path()` / `base_path()` directly —
 * the `noStoragePathHardCodedOutsideUserDataPathService` arch invariant
 * forbids it.
 *
 * Fresh-install detection reports whether any user exists after
 * migrations have run — a zero-user post-migration state is the signal
 * for the desktop module's welcome screen to short-circuit into `/signup`.
 *
 * No global helpers / facade calls — every collaborator arrives through
 * the constructor.
 */
final class FirstLaunchBootstrap
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly UserDataPathService $paths,
        private readonly DatabaseManager $db,
        private readonly EnsureAppKey $ensureAppKey,
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
     * Run every pending migration, then ensure the install's APP_KEY is
     * present. Both steps are idempotent — the migrator's `run()` is a
     * no-op when nothing is pending, and `EnsureAppKey::run()` early-
     * returns when its sentinel file already exists. When the migrations
     * repository does not yet exist it is created first so the migrator
     * can record the first run.
     */
    public function runPendingMigrations(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        $this->migrator->run($this->migrationPaths());

        $this->ensureAppKey->run();
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
