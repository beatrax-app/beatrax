<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Events\UpdateInstallRequested;
use Modules\Core\Public\Services\UserDataPathService;
use Native\Desktop\AutoUpdater;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final readonly class TriggerUpdateDownload
{
    public function __construct(
        private AutoUpdater $autoUpdater,
        private LoggerInterface $logger,
    ) {}

    public function handle(UpdateInstallRequested $event): void
    {
        if (UserDataPathService::isMobileRuntime()) {
            return;
        }

        // The consenting click starts the download that autoDownload=false held
        // back; UpdateDownloaded then re-verifies the binary before it installs,
        // so nothing lands on disk-then-runs without passing the Ed25519 gate.
        $this->logger->info('TriggerUpdateDownload: user consented to install an update.', [
            'version' => $event->latestVersion,
        ]);

        $this->autoUpdater->downloadUpdate();
    }
}
