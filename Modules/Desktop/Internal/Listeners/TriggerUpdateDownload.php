<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Events\UpdateInstallRequested;
use Modules\Core\Public\Services\UserDataPathService;
use Native\Desktop\AutoUpdater;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md
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

        // The consenting click is the only thing that starts the download
        // autoDownload=false holds back.
        $this->logger->info('TriggerUpdateDownload: user consented to install an update.', [
            'version' => $event->latestVersion,
        ]);

        $this->autoUpdater->downloadUpdate();
    }
}
