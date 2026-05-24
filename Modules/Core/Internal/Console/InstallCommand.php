<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;

/**
 * Idempotent first-run setup for the local-only single-user app:
 *
 * 1. Refuses to run when the SQLite database path is inside a cloud-sync
 *    folder (iCloud Drive / OneDrive / Dropbox / Mobile Documents).
 * 2. Runs pending migrations.
 * 3. Creates User id=1 if absent. Re-running with the same username does
 *    NOT update the password — a dedicated reset-password command will
 *    land in a later operational-hardening phase.
 * 4. Always dispatches `UserInstalled` for the resolved user (whether
 *    newly created or pre-existing). Listeners (`SeedDefaultCategoryTree`,
 *    other module-local install hooks) are required to be idempotent, so
 *    re-running install heals any seeded reference data that went missing
 *    between the user's original install and a fresh listener wire-up
 *    (e.g. the default category tree shipping in a module that wasn't
 *    loaded when User id=1 was first created).
 *
 * The `--launchd` mode is a separate code path: it installs three
 * macOS LaunchAgent plists from `deploy/launchd/*.plist` to
 * `~/Library/LaunchAgents/`, substituting `{{ABS_PHP_BINARY}}` (the
 * PHP_BINARY constant from the currently-running interpreter) and
 * `{{ABS_PROJECT_ROOT}}` (the project's base path) at install time
 * so the resulting plists carry the user's actual paths — not
 * placeholders. The Redis plist is OPTIONAL; pass `--without-redis`
 * when Docker Desktop auto-starts the container on login.
 *
 * The `bootstrapPlist()` helper is `protected` (not private) so the
 * InstallLaunchdCommandTest can subclass + override it to capture
 * what would be passed to `launchctl bootstrap` without actually
 * invoking it on the developer's real machine.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:install
        {--username= : Username for the single-user account}
        {--password= : Password for the single-user account}
        {--period-start-day=1 : Period start day (1-28, 1 = calendar month, 25 = salary cycle)}
        {--launchd : Install macOS launchd plists for Horizon + scheduler + (optional) Redis}
        {--without-redis : Skip the optional Redis plist (use when Docker Desktop auto-starts the container on login)}';

    /** @var string */
    protected $description = 'Idempotent first-run setup: validate DB path, run migrations, create or re-confirm the single user, and re-dispatch UserInstalled so seed listeners can heal missing reference data. Pass --launchd to install macOS background workers.';

    /**
     * Path tokens that indicate a cloud-sync folder. Matched case-insensitively
     * against the resolved real path of the SQLite database before any file IO
     * runs. The list combines per-vendor product names with the canonical
     * macOS Monterey+ mountpoint `Library/CloudStorage` which catches every
     * cloud provider Apple registers with the system.
     *
     * @var list<string>
     */
    private const CLOUD_SYNC_TOKENS = [
        'Library/CloudStorage',
        'Mobile Documents',
        'iCloud Drive',
        'iCloudDrive',
        '.icloud',
        'OneDrive',
        'Dropbox',
        'Google Drive',
        'GoogleDrive',
        'google_drive',
        'My Drive',
        'Box Sync',
        'Box.com',
        'pCloud Drive',
        'pCloudDrive',
        'Sync.com',
        'MEGAsync',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
        private readonly Filesystem $files,
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('launchd') === true) {
            return $this->installLaunchdPlists();
        }

        $dbPathValue = $this->config->get('database.connections.sqlite.database');
        $dbPath = is_string($dbPathValue) ? $dbPathValue : '';

        $resolvedPath = self::resolveRealPath($dbPath);

        foreach (self::CLOUD_SYNC_TOKENS as $token) {
            if (stripos($resolvedPath, $token) !== false) {
                $this->error(sprintf(
                    "Refusing to install: database path '%s' (resolved to '%s') is inside a cloud-sync folder (%s).",
                    $dbPath,
                    $resolvedPath,
                    $token,
                ));
                $this->line('beatrax is local-only — move database.sqlite outside iCloud Drive, OneDrive, Dropbox, or any other cloud-sync folder before running install again.');

                return self::FAILURE;
            }
        }

        $migrateResult = $this->call('migrate', ['--force' => true]);
        if ($migrateResult !== 0) {
            return self::FAILURE;
        }

        // Currency reference data is owned by the Ledger module. Referenced
        // by FQN string so InstallCommand does not import a class from
        // another module's private namespace.
        $seedResult = $this->call('db:seed', [
            '--class' => 'Modules\\Ledger\\Database\\Seeders\\CurrenciesSeeder',
            '--force' => true,
        ]);
        if ($seedResult !== 0) {
            return self::FAILURE;
        }

        $existingId = self::resolveExistingUserId($this->db);
        if ($existingId !== null) {
            $this->info('A user account is already installed. Password is unchanged; re-running install re-confirms the existing account.');
            $this->line('Password changes require a dedicated reset-password command; re-running install with a different password is intentionally a no-op.');

            $this->events->dispatch(new UserInstalled($existingId));

            return self::SUCCESS;
        }

        $username = strtolower(trim($this->resolveStringInput('username', 'Username')));
        $password = $this->resolveStringInput('password', 'Password', secret: true);
        $periodStartDay = $this->resolvePeriodStartDay();

        if ($username === '') {
            $this->error('Refusing to install: username is required.');

            return self::FAILURE;
        }
        if ($password === '') {
            $this->error('Refusing to install: password is required.');

            return self::FAILURE;
        }

        $user = User::create([
            'username' => $username,
            'password' => $password,
            'period_start_day' => $periodStartDay,
        ]);

        $this->events->dispatch(new UserInstalled($user->id));

        $this->info(sprintf(
            'Installed User id=%d with username %s, period_start_day=%d.',
            $user->id,
            $user->username,
            $user->period_start_day,
        ));

        return self::SUCCESS;
    }

    /**
     * Returns the smallest id in the `users` table, or null when the table
     * is empty. Uses the raw query builder so the lookup stays out of the
     * Eloquent global-scope path (`UserScope` filters by authenticated user
     * id, which is meaningless during a console install run).
     */
    private static function resolveExistingUserId(DatabaseManager $db): ?int
    {
        $row = $db->connection()
            ->table('users')
            ->orderBy('id')
            ->select(['id'])
            ->first();

        if ($row === null) {
            return null;
        }

        $value = $row->id;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Resolves the database file path through `realpath` so symlink targets
     * are detected. When the database file does not yet exist on disk (first
     * install), falls back to the parent directory's real path. When even
     * that fails (path is entirely synthetic), returns the original input so
     * the token scan still gets a chance against the raw string.
     */
    private static function resolveRealPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $resolved = @realpath($path);
        if (is_string($resolved)) {
            return $resolved;
        }

        $dir = dirname($path);
        $resolvedDir = @realpath($dir);
        if (is_string($resolvedDir)) {
            return $resolvedDir.'/'.basename($path);
        }

        return $path;
    }

    /**
     * Reads a string-valued option, falling back to an interactive prompt when
     * the option was not supplied.
     */
    private function resolveStringInput(string $option, string $prompt, bool $secret = false): string
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            $value = $secret ? $this->secret($prompt) : $this->ask($prompt);
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Reads the period-start-day option (or prompts for it) and clamps the
     * value into the valid 1..28 window.
     */
    private function resolvePeriodStartDay(): int
    {
        $raw = $this->option('period-start-day');

        if ($raw === null || $raw === '') {
            $raw = $this->ask('Period start day (1-28, 1=calendar month)', '1');
        }

        $day = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min(28, $day));
    }

    /**
     * Install macOS LaunchAgent plists for Horizon + scheduler +
     * (optional) Redis. Refuses to run on non-Darwin hosts — the
     * plists are macOS-only.
     *
     * For each plist:
     *  1. Read the template from `deploy/launchd/com.beatrax.{name}.plist`.
     *  2. Substitute `{{ABS_PHP_BINARY}}` (PHP_BINARY) +
     *     `{{ABS_PROJECT_ROOT}}` (Application::basePath()) in the contents.
     *  3. Ensure ~/Library/LaunchAgents/ exists (chmod 700 on first
     *     create — restricts read access to the user).
     *  4. Write the rendered plist to `~/Library/LaunchAgents/`.
     *  5. Bootstrap via `launchctl bootstrap gui/{uid}` so launchd
     *     picks up the change without needing a reboot. The bootstrap
     *     call is funnelled through `bootstrapPlist()` so tests can
     *     stub it.
     */
    private function installLaunchdPlists(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('launchd plists are macOS-only; aborting.');

            return self::FAILURE;
        }

        $home = $_SERVER['HOME'] ?? null;
        if (! is_string($home) || $home === '') {
            $this->error('HOME environment variable is not set; cannot resolve ~/Library/LaunchAgents.');

            return self::FAILURE;
        }

        $launchAgentsDir = $this->resolveLaunchAgentsDir($home);
        if (! $this->files->isDirectory($launchAgentsDir)) {
            $this->files->makeDirectory($launchAgentsDir, 0700, recursive: true);
        }

        $plistNames = ['horizon', 'scheduler'];
        if ($this->option('without-redis') !== true) {
            $plistNames[] = 'redis';
        }

        $substitutions = [
            '{{ABS_PHP_BINARY}}' => PHP_BINARY,
            '{{ABS_PROJECT_ROOT}}' => $this->app->basePath(),
        ];

        $uid = self::resolveCurrentUid();

        foreach ($plistNames as $name) {
            $sourcePath = $this->app->basePath('deploy/launchd/com.beatrax.'.$name.'.plist');
            if (! $this->files->exists($sourcePath)) {
                $this->error("Source plist not found: {$sourcePath}");

                return self::FAILURE;
            }

            $template = $this->files->get($sourcePath);
            $rendered = strtr($template, $substitutions);
            $targetPath = $launchAgentsDir.'/com.beatrax.'.$name.'.plist';
            $this->files->put($targetPath, $rendered);
            $this->info("Wrote {$targetPath}");

            $bootstrapExit = $this->bootstrapPlist($uid, $targetPath);
            if ($bootstrapExit === 0) {
                $this->info("Loaded com.beatrax.{$name}");
            } else {
                $this->warn("launchctl bootstrap exited {$bootstrapExit} for com.beatrax.{$name} (may already be loaded; check `launchctl list | grep beatrax`)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the LaunchAgents directory path. Extracted so tests can
     * override + redirect into a sandbox without mutating the
     * developer's real ~/Library/LaunchAgents.
     */
    protected function resolveLaunchAgentsDir(string $home): string
    {
        return $home.'/Library/LaunchAgents';
    }

    /**
     * Invoke `launchctl bootstrap gui/{uid} {plistPath}` and return
     * its exit code. Extracted so tests can override to capture the
     * intended bootstrap target without actually mutating the
     * developer's running launchd.
     *
     * Returns the launchctl exit code (0 on success; non-zero is
     * surfaced as a warning, not a hard failure, because launchctl
     * exits non-zero for "already loaded" which is fine on re-install).
     */
    protected function bootstrapPlist(int $uid, string $plistPath): int
    {
        $cmd = 'launchctl bootstrap gui/'.$uid.' '.escapeshellarg($plistPath);
        $exitCode = 1;
        passthru($cmd, $exitCode);

        return $exitCode;
    }

    /**
     * Resolve the current real user id. posix_getuid() is missing on
     * Windows; the launchd path is Darwin-only so the function is
     * available wherever this method is reached, but the safety
     * fallback returns 0 (root) which makes the resulting launchctl
     * call fail visibly rather than silently writing to the wrong
     * user's launchd.
     */
    private static function resolveCurrentUid(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }

        return 0;
    }
}
