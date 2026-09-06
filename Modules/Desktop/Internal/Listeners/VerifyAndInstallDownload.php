<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Native\Desktop\AutoUpdater;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md
 */
final readonly class VerifyAndInstallDownload
{
    public function __construct(
        private ElectronUpdateChannel $channel,
        private PublisherManifestFetcher $fetcher,
        private AutoUpdater $autoUpdater,
        private LoggerInterface $logger,
        private SystemAlertWriter $alerts,
    ) {}

    public function handle(UpdateDownloaded $event): void
    {
        if (UserDataPathService::isMobileRuntime()) {
            return;
        }

        $manifest = $this->fetcher->fetch($this->channel->channel());

        // Split from the verification branch below so neither line names the
        // other's cause. Having no manifest is offline, an unconfigured feed, or
        // a reader who switched the check off between consenting and this event
        // — none of them a tampering signal, and none of them critical.
        if ($manifest === null) {
            $this->logger->warning('VerifyAndInstallDownload: no publisher manifest to check the download against; nothing was installed.', [
                'version' => $event->version,
            ]);

            return;
        }

        // Fail closed on every unverifiable branch, leaving the downloaded file on
        // disk uninstalled rather than trusting an unproven update.
        if (! $this->channel->verifyManifest($manifest['body'], $manifest['signature'])
            || $manifest['latest_version'] !== $event->version
            || ! $this->channel->verifyBinary($event->downloadedFile, $manifest['sha512_hex'])) {
            $this->logger->critical('VerifyAndInstallDownload: refused an update that failed publisher verification.', [
                'version' => $event->version,
            ]);

            // A log line is not a reader. They consented to an install, waited
            // through a download, and were told nothing at all — which reads as
            // "it worked" and invites the same click again. The one branch that
            // may mean tampering is the one branch that has to surface.
            $line = CopyLine::of('core::alerts.messages.update_refused', ['version' => $event->version]);

            $this->alerts->raiseOnceSystemWide(
                kind: UpdateAlertKind::Refused->value,
                severity: UpdateAlertKind::Refused->severity()->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line) + ['refusedVersion' => $event->version],
            );

            return;
        }

        $this->autoUpdater->quitAndInstall();
    }
}
