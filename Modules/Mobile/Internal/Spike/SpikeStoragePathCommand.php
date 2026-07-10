<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Spike;

use Illuminate\Console\Command;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * Throwaway Wave-0 topology probe (Phase 15-02, Spike B).
 *
 * Dumps the four values a later wave (Plan 04's on-device migrate-on-launch)
 * needs to reason about the mobile storage root: the two NativePHP-mobile
 * environment signals plus where Laravel and the app's own path service each
 * believe user data lives. On a real device NativePHP mobile sets
 * `NATIVEPHP_STORAGE_PATH` to the app sandbox's writable container and
 * `NATIVEPHP_PLATFORM` to `ios` / `android`; `UserDataPathService` already
 * honours `NATIVEPHP_STORAGE_PATH` (its `storageRoot()` / `databaseFile()`
 * accessors read it via `getenv()`), so this probe confirms the encrypted
 * SQLite file will land inside the sandbox rather than the project tree.
 *
 * Read via `getenv()` (not Laravel's `env()`) to mirror `UserDataPathService`
 * exactly — `getenv()` is unconditional at every boot stage and reflects the
 * live process environment the NativePHP shell exports.
 *
 * @internal Throwaway spike probe — not shipped wiring. Registered only from
 *           the mobile-app root's bootstrap (never the shared MobileServiceProvider).
 */
final class SpikeStoragePathCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:spike-storage';

    /** @var string */
    protected $description = 'Spike B: dump the NativePHP mobile storage-path signals and resolved user-data paths.';

    public function __construct(
        private readonly UserDataPathService $paths,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $storageEnv = getenv('NATIVEPHP_STORAGE_PATH');
        $platformEnv = getenv('NATIVEPHP_PLATFORM');

        $this->table(
            ['signal', 'value'],
            [
                ['getenv(NATIVEPHP_STORAGE_PATH)', $this->render($storageEnv)],
                ['getenv(NATIVEPHP_PLATFORM)', $this->render($platformEnv)],
                ['storage_path()', storage_path()],
                ['UserDataPathService::databasePath()', $this->paths->databasePath()],
                ['UserDataPathService::storagePath()', $this->paths->storagePath()],
            ],
        );

        return self::SUCCESS;
    }

    private function render(string|false $value): string
    {
        return $value === false ? '(unset)' : $value;
    }
}
