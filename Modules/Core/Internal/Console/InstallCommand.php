<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;

/**
 * Idempotent first-run setup for the local-only single-user app:
 *
 * 1. Refuses to run when the SQLite database path is inside a cloud-sync
 *    folder (iCloud Drive / OneDrive / Dropbox / Mobile Documents).
 * 2. Runs pending migrations.
 * 3. Creates User id=1 if absent. Re-running with the same email is a no-op
 *    and never silently updates the password — a dedicated reset-password
 *    command will land in a later operational-hardening phase.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:install
        {--email= : Email for the single-user account}
        {--password= : Password for the single-user account}
        {--period-start-day=1 : Period start day (1-28, 1 = calendar month, 25 = salary cycle)}';

    /** @var string */
    protected $description = 'Idempotent first-run setup: validate DB path, run migrations, create the single user.';

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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
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
                $this->line('diederik is local-only — move database.sqlite outside iCloud Drive, OneDrive, Dropbox, or any other cloud-sync folder before running install again.');

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

        if (User::query()->exists()) {
            $this->info('A user account is already installed. Nothing to do.');
            $this->line('Password changes require a dedicated reset-password command; re-running install with a different password is intentionally a no-op.');

            return self::SUCCESS;
        }

        $email = $this->resolveStringInput('email', 'Email');
        $password = $this->resolveStringInput('password', 'Password', secret: true);
        $periodStartDay = $this->resolvePeriodStartDay();

        if ($email === '') {
            $this->error('Refusing to install: email is required.');

            return self::FAILURE;
        }
        if ($password === '') {
            $this->error('Refusing to install: password is required.');

            return self::FAILURE;
        }

        $user = User::create([
            'email' => $email,
            'password' => $password,
            'period_start_day' => $periodStartDay,
        ]);

        $this->events->dispatch(new UserInstalled($user->id));

        $this->info(sprintf(
            'Installed User id=%d with email %s, period_start_day=%d.',
            $user->id,
            $user->email,
            $user->period_start_day,
        ));

        return self::SUCCESS;
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
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        $dir = dirname($path);
        $resolvedDir = @realpath($dir);
        if (is_string($resolvedDir) && $resolvedDir !== '') {
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
}
