<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

use Closure;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\AppWindow;
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
final readonly class NativeAppServiceProvider implements ProvidesPhpIni
{
    private const int WINDOW_WIDTH = 1100;

    private const int WINDOW_HEIGHT = 800;

    // The bundled PHP's stock 2M/8M defaults sit below the wizard's own 10 MB
    // Livewire validator ceiling, so a larger statement failed at the PHP layer
    // with no actionable error. 20M matches the wizard's largest single upload.
    private const string UPLOAD_MAX_FILESIZE = '20M';

    private const string POST_MAX_SIZE = '20M';

    // The stock 30s is too tight for the auto-updater's feed check on a slow
    // network and for heavy ingestion on a cold cache.
    private const string MAX_EXECUTION_TIME = '120';

    // Stock is Off, and then anything rendering a trace writes the first 15
    // characters of every string argument into the 0644 daily log — on a parse
    // frame, a row of the reader's statement. SafeTrace no longer depends on
    // this; nothing else that renders a trace was ever asked to.
    private const string EXCEPTION_IGNORE_ARGS = '1';

    public function __construct(
        private WindowManager $windows,
        private AppMenuBuilder $appMenu,
        private FirstLaunchBootstrap $bootstrap,
        private ConsoleKernel $console,
        private LoggerInterface $logger,
        private SyncListenerProcess $syncListener,
        private RelayListenerProcess $relayListener,
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
            'zend.exception_ignore_args' => self::EXCEPTION_IGNORE_ARGS,
        ];
    }

    public function boot(): void
    {
        $this->prepareBeforeWindow();

        $this->windows->open(AppWindow::ID)
            ->width(self::WINDOW_WIDTH)
            ->height(self::WINDOW_HEIGHT)
            ->rememberState();

        Menu::create(...$this->appMenu->build());
    }

    // The window IS the recovery: a failed migration leaves EnsureDatabaseReady
    // redirecting to desktop.setup, whose poll() re-drives it. Thrown from here
    // instead, the app opened no window at all and that screen was unreachable.
    private function prepareBeforeWindow(): void
    {
        // Before the window opens, so the first request it makes sees a fully
        // migrated schema.
        $this->attempt('first-launch migrate', fn () => $this->bootstrap->runPendingMigrations());
        $this->attempt('sync listener', fn () => $this->syncListener->startIfEnabled());
        $this->attempt('relay listener', fn () => $this->relayListener->startIfEnabled());

        $this->precompileViews();
    }

    private function attempt(string $step, Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $this->logger->error(
                "NativePHP boot: {$step} failed; the window still opens so the setup screen can recover.",
                ['exception' => $e],
            );
        }
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
