<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

/*
 * Drives path resolution end-to-end under a simulated NativePHP storage
 * root: with NATIVEPHP_STORAGE_PATH pointing at a per-test temp directory,
 * UserDataPathService and the artisan commands that consume it must land
 * every file under that root instead of the project tree.
 *
 * Wave-0 scaffold: this file currently carries only the beforeEach /
 * afterEach harness (per-test temp dir, env set/clear, directory creation
 * SQLite needs, teardown cascade) plus one placeholder it() body. Plan 03
 * fills in the real migrate:fresh / db:backup / OAuth-secrets assertions
 * once every call site has been migrated through UserDataPathService.
 */

beforeEach(function (): void {
    // Cached config freezes resolved paths into a flat array, which would
    // mask the env-var branch entirely (Pitfall 4). Refuse to run if the
    // harness somehow booted with cached config.
    expect($this->app->configurationIsCached())->toBeFalse();

    // Collision-free per-test root under the system temp dir.
    $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8));

    // SQLite will not create the parent directory of the database file,
    // and db:backup writes into storage/app/backups (Pitfall 5) — create
    // the directory tree the simulated build expects before any command
    // runs against the root.
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'database', 0o755, true);
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups', 0o755, true);
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'secrets', 0o755, true);

    // UserDataPathService reads getenv() directly, so putenv() is the only
    // mechanism that influences it (Pitfall 3). Clear with no `=` value.
    putenv('NATIVEPHP_STORAGE_PATH='.$this->tmpRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;
    if (! is_dir($tmpRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($tmpRoot);
});

it('resolves paths under a simulated NativePHP storage root', function (): void {
    // Wave-0 placeholder: proves the harness boots and the env var takes
    // effect. Plan 03 replaces this with the real migrate:fresh / db:backup
    // / OAuth-secrets assertions once all call sites are migrated.
    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;

    expect(UserDataPathService::storageBase())->toBe($tmpRoot);
})->todo('Plan 03 fills the migrate:fresh / db:backup / OAuth-secrets assertions');
