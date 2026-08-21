<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Modules\Desktop\Internal\Native\RelayListenerProcess;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Contracts\WindowManager;
use Native\Desktop\Facades\Menu;
use Psr\Log\LoggerInterface;
use Throwable;

// Resolved from config/nativephp.php's `provider` key, so its constructor is
// container-autowired. Menu::create() has no container-bound alternative, so this
// file joins the facade allow-list.
final class NativeAppServiceProvider implements ProvidesPhpIni
{
    private const WINDOW_WIDTH = 1100;

    private const WINDOW_HEIGHT = 800;

    // The bundled PHP's stock 2M/8M defaults sit below the wizard's own 10 MB
    // Livewire validator ceiling, so a larger statement failed at the PHP layer
    // with no actionable error. 20M matches the wizard's largest single upload.
    private const UPLOAD_MAX_FILESIZE = '20M';

    private const POST_MAX_SIZE = '20M';

    // The stock 30s is too tight for the auto-updater's feed check on a slow
    // network and for heavy ingestion on a cold cache.
    private const MAX_EXECUTION_TIME = '120';

    public function __construct(
        private readonly WindowManager $windows,
        private readonly AppMenuBuilder $appMenu,
        private readonly FirstLaunchBootstrap $bootstrap,
        private readonly ConsoleKernel $console,
        private readonly LoggerInterface $logger,
        private readonly SyncListenerProcess $syncListener,
        private readonly RelayListenerProcess $relayListener,
    ) {}

    /**
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return [
            'upload_max_filesize' => self::UPLOAD_MAX_FILESIZE,
            'post_max_size' => self::POST_MAX_SIZE,
            'max_execution_time' => self::MAX_EXECUTION_TIME,
        ];
    }

    public function boot(): void
    {
        // Before the window opens, so the first request it makes sees a fully
        // migrated schema.
        $this->bootstrap->runPendingMigrations();

        $this->syncListener->startIfEnabled();
        $this->relayListener->startIfEnabled();

        $this->precompileViews();

        // Width/height are first-launch defaults only; rememberState() wins after.
        $this->windows->open('main')
            ->width(self::WINDOW_WIDTH)
            ->height(self::WINDOW_HEIGHT)
            ->rememberState();

        Menu::create(...$this->appMenu->build());
    }

    // On Windows, two simultaneous Livewire requests racing to compile the same
    // view can lose the rename() to an antivirus-locked destination; precompiling
    // before the first request removes the race, with one retry for a stray lock.
    private function precompileViews(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                $this->console->call('view:cache');

                return;
            } catch (Throwable $e) {
                $this->logger->warning(
                    "NativePHP boot: view:cache attempt {$attempt} failed",
                    ['exception' => $e],
                );
            }
        }

        $this->logger->warning(
            'NativePHP boot: view:cache exhausted retries; views will compile on demand',
        );
    }
}
