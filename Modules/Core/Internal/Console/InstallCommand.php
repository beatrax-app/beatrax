<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Internal\Enums\OsFamily;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\SqliteDatabase;

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

    // Per-vendor product names plus `Library/CloudStorage`, the canonical macOS
    // mountpoint that catches every provider Apple registers with the system.
    /**
     * @var list<string>
     */
    private const array CLOUD_SYNC_TOKENS = [
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

        return $this->runInstall();
    }

    private function runInstall(): int
    {
        $dbPath = SqliteDatabase::livePath($this->config) ?? '';
        $resolvedPath = self::resolveRealPath($dbPath);

        $token = $this->cloudSyncToken($resolvedPath);
        if ($token !== null) {
            $this->error(sprintf(
                "Refusing to install: database path '%s' (resolved to '%s') is inside a cloud-sync folder (%s).",
                $dbPath,
                $resolvedPath,
                $token,
            ));
            $this->line('Beatrax is local-only — move database.sqlite outside iCloud Drive, OneDrive, Dropbox, or any other cloud-sync folder before running install again.');

            return self::FAILURE;
        }

        if (! $this->runMigrationsAndSeed()) {
            return self::FAILURE;
        }

        return $this->ensureUser();
    }

    private function cloudSyncToken(string $resolvedPath): ?string
    {
        foreach (self::CLOUD_SYNC_TOKENS as $token) {
            if (stripos($resolvedPath, $token) !== false) {
                return $token;
            }
        }

        return null;
    }

    private function runMigrationsAndSeed(): bool
    {
        if ($this->call('migrate', ['--force' => true]) !== 0) {
            return false;
        }

        // Referenced by name so Core does not import a class from another
        // module's private namespace.
        return $this->call('db:seed', [
            '--class' => 'Modules\\Ledger\\Database\\Seeders\\CurrenciesSeeder',
            '--force' => true,
        ]) === 0;
    }

    private function ensureUser(): int
    {
        $existingId = self::resolveExistingUserId($this->db);
        if ($existingId !== null) {
            $this->info('A user account is already installed. Password is unchanged; re-running install re-confirms the existing account.');
            $this->line('Password changes require a dedicated reset-password command; re-running install with a different password is intentionally a no-op.');

            $this->events->dispatch(new UserInstalled($existingId));

            return self::SUCCESS;
        }

        $username = Username::normalize($this->resolveStringInput('username', 'Username'));
        $password = $this->resolveStringInput('password', 'Password', secret: true);
        $periodStartDay = $this->resolvePeriodStartDay();

        $missing = match (true) {
            $username === '' => 'username',
            $password === '' => 'password',
            default => '',
        };
        if ($missing !== '') {
            $this->error('Refusing to install: '.$missing.' is required.');

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

    // Raw query builder so UserScope's global scope — which filters by the
    // authenticated user id — does not apply during a console install run.
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

    // realpath so a symlink into a cloud folder is detected. On first install
    // the file does not exist yet, so it falls back to the parent directory,
    // then to the raw input so the token scan still gets a chance at it.
    private static function resolveRealPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $resolved = @realpath($path);
        if (is_string($resolved)) {
            return $resolved;
        }

        $resolvedDir = @realpath(dirname($path));

        return is_string($resolvedDir) ? $resolvedDir.'/'.basename($path) : $path;
    }

    private function resolveStringInput(string $option, string $prompt, bool $secret = false): string
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            $value = $secret ? $this->secret($prompt) : $this->ask($prompt);
        }

        return is_string($value) ? $value : '';
    }

    private function resolvePeriodStartDay(): int
    {
        $raw = $this->option('period-start-day');

        if ($raw === null || $raw === '') {
            $raw = $this->ask('Period start day (1-28, 1=calendar month)', '1');
        }

        $day = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min(28, $day));
    }

    private function installLaunchdPlists(): int
    {
        if (OsFamily::current() !== OsFamily::Darwin) {
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

        return $this->writePlists($plistNames, $launchAgentsDir, $substitutions, self::resolveCurrentUid());
    }

    // A non-zero launchctl bootstrap is only a warning: launchctl exits
    // non-zero for an already-loaded agent, which is fine on re-install.
    /**
     * @param  list<string>  $plistNames
     * @param  array<string, string>  $substitutions
     */
    private function writePlists(array $plistNames, string $launchAgentsDir, array $substitutions, int $uid): int
    {
        foreach ($plistNames as $name) {
            $sourcePath = $this->app->basePath('deploy/launchd/com.beatrax.'.$name.'.plist');
            if (! $this->files->exists($sourcePath)) {
                $this->error("Source plist not found: {$sourcePath}");

                return self::FAILURE;
            }

            $rendered = strtr($this->files->get($sourcePath), $substitutions);
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

    // The test subclass overrides both to keep the suite off the real machine.
    // Narrowing either to private would not error — PHP lets a subclass declare
    // a same-named private method while the parent keeps calling its own — so
    // the tests would silently write real LaunchAgents and run launchctl.
    protected function resolveLaunchAgentsDir(string $home): string
    {
        return $home.'/Library/LaunchAgents';
    }

    protected function bootstrapPlist(int $uid, string $plistPath): int
    {
        $cmd = 'launchctl bootstrap gui/'.$uid.' '.escapeshellarg($plistPath);
        $exitCode = 1;
        passthru($cmd, $exitCode);

        return $exitCode;
    }

    // posix_getuid() is missing on Windows. The fallback returns 0 (root) so a
    // missing function fails the launchctl call visibly, rather than silently
    // writing to the wrong user's launchd.
    private static function resolveCurrentUid(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }

        return 0;
    }
}
