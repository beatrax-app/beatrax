<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * On-device first-launch DB bootstrap — the mobile mirror of
 * `Modules\Desktop\Internal\Native\FirstLaunchBootstrap`, wired at the
 * mobile root's own proven attach point instead of the desktop's
 * `EnsureDatabaseReady` middleware (15-TOPOLOGY-SPIKE-FINDINGS.md: the
 * mobile `bootstrap/app.php` deliberately omits that desktop-only gate;
 * this class + its `->booted()` hook are the mobile replacement).
 *
 * Runs the framework `Migrator` against whatever SQLite connection is
 * currently configured — NEVER a shelled-out SQLite command-line binary, which
 * 15-SPIKE-FINDINGS.md (Spike B, run on a real iPhone) confirmed is absent
 * on-device. The caller (`mobile-app/bootstrap/app.php`'s `->booted()`
 * hook) is responsible for FIRST reconciling
 * `config('database.connections.sqlite.database')` to
 * `UserDataPathService::databaseFile()` before invoking this class, so the
 * migrator always targets the one canonical sandboxed path — this class
 * does not repeat that reconciliation itself, it only asserts the
 * canonical path via `databasePath()` for callers/tests that want to
 * confirm the two agree.
 *
 * `hasPendingMigrations()` gates `runPendingMigrations()` so repeated app
 * launches never re-run the migrator once the on-device DB is caught up —
 * idempotent, no daemon, no persistent process.
 *
 * Deliberately does NOT chain `Modules\Core\Public\Bootstrap\EnsureAppKey`
 * (unlike the desktop analog): that action rewrites `.env` via
 * `key:generate --force`, which assumes a writable on-disk `.env` next to
 * a placeholder APP_KEY — a desktop-bundle assumption this plan does not
 * extend to the mobile build. Out of this plan's locked scope
 * (`hasPendingMigrations()`/`runPendingMigrations()`/`isFreshInstall()`
 * only, per 15-04-PLAN.md's artifact exports).
 *
 * No global helpers / facade calls — every collaborator arrives through
 * the constructor, mirroring the desktop analog's discipline.
 */
final class MobileFirstLaunchBootstrap
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly UserDataPathService $paths,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The canonical on-device SQLite path — routed through
     * `UserDataPathService` so this stays the single path authority.
     */
    public function databasePath(): string
    {
        return $this->paths->databasePath();
    }

    /**
     * True when at least one migration on disk has not been recorded in
     * the `migrations` table. When the repository itself does not yet
     * exist (a brand-new on-device SQLite file) every migration counts as
     * pending — the migrator creates the repository on its first run.
     */
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

    /**
     * Run every pending migration against the currently-configured SQLite
     * connection. Idempotent — the migrator's own `run()` is a no-op when
     * nothing is pending. When the migrations repository does not yet
     * exist it is created first so the migrator can record the first run.
     */
    public function runPendingMigrations(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        $this->migrator->run($this->migrationPaths());
    }

    /**
     * Fresh-install signal, mirroring the desktop analog's D-22 shape.
     * After migrations have run, an empty `users` table marks the first
     * launch of this on-device install.
     */
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
