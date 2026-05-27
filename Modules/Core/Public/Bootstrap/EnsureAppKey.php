<?php

declare(strict_types=1);

namespace Modules\Core\Public\Bootstrap;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * First-launch APP_KEY regeneration with sentinel-driven idempotency.
 *
 * A bundled build ships with a placeholder `APP_KEY=` value in the
 * shipped `.env` template — the placeholder MUST be replaced with a
 * per-install secret the first time the app boots so the framework's
 * encrypter has a real key and so two installs of the same bundle do
 * not share an `APP_KEY`. This action performs that replacement by
 * delegating to the framework's `key:generate --force` command, which
 * writes the new key into the on-disk `.env` and updates the runtime
 * config.
 *
 * The sentinel file is a 0-byte marker stored under the user-data
 * storage root via `UserDataPathService::appPath()`; its presence
 * signals "this install already minted its APP_KEY." Subsequent
 * invocations short-circuit, so the action is safe to wire into a
 * chained-bootstrap path that runs on every launch.
 *
 * Both collaborators arrive through constructor DI: the path service
 * resolves the sentinel location and the console kernel executes the
 * framework's `key:generate` command. No facade calls or global
 * helpers are used.
 */
final class EnsureAppKey
{
    /** Sentinel filename relative to the user-data `app/` directory. */
    public const SENTINEL_FILENAME = 'first-launch.app-key-generated';

    public function __construct(
        private readonly UserDataPathService $paths,
        private readonly ConsoleKernel $artisan,
    ) {}

    public function run(): void
    {
        $sentinel = $this->paths->appRelative(self::SENTINEL_FILENAME);

        if (is_file($sentinel)) {
            return;
        }

        $this->artisan->call('key:generate', ['--force' => true]);

        $directory = dirname($sentinel);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($sentinel, '');
    }
}
