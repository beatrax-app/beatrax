<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\UserDataPathService;
use Native\Desktop\AutoUpdater;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final readonly class VerifyAndInstallDownload
{
    public function __construct(
        private ElectronUpdateChannel $channel,
        private PublisherManifestFetcher $fetcher,
        private AutoUpdater $autoUpdater,
        private LoggerInterface $logger,
    ) {}

    public function handle(UpdateDownloaded $event): void
    {
        if (UserDataPathService::isMobileRuntime()) {
            return;
        }

        $manifest = $this->fetcher->fetch($this->channel->channel());

        // Fail closed on every unverifiable branch — feed unreachable, bad
        // Ed25519 signature, a version the signed manifest does not name, or a
        // downloaded binary whose SHA512 is not the one it names — leaving the
        // file on disk uninstalled rather than trusting an unproven update.
        if ($manifest === null
            || ! $this->channel->verifyManifest($manifest['body'], $manifest['signature'])
            || $manifest['latest_version'] !== $event->version
            || ! $this->channel->verifyBinary($event->downloadedFile, $manifest['sha512_hex'])) {
            $this->logger->critical('VerifyAndInstallDownload: refused an update that failed publisher verification.', [
                'version' => $event->version,
            ]);

            return;
        }

        $this->autoUpdater->quitAndInstall();
    }
}
