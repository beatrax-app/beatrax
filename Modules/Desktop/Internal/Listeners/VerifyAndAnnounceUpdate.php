<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Actions\RecordUpdateAvailableAlert;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\UserDataPathService;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final readonly class VerifyAndAnnounceUpdate
{
    public function __construct(
        private ElectronUpdateChannel $channel,
        private PublisherManifestFetcher $fetcher,
        private RecordUpdateAvailableAlert $record,
        private LoggerInterface $logger,
    ) {}

    public function handle(UpdateAvailable $event): void
    {
        // A desktop-only event, but the mobile runtime must never act on a
        // desktop binary update — the app stores own that path — so it is
        // refused here as well as gated at registration.
        if (UserDataPathService::isMobileRuntime()) {
            return;
        }

        $manifest = $this->channel->poll($this->fetcher);
        if ($manifest === null) {
            return;
        }

        // electron-updater found an update; announce it only when the
        // publisher-signed manifest agrees on the version, so a feed serving a
        // signed manifest for one version and a binary for another never
        // reaches the banner the user consents from.
        if ($manifest->latestVersion !== $event->version) {
            $this->logger->warning('VerifyAndAnnounceUpdate: signed manifest version disagrees with the update offered.', [
                'offered' => $event->version,
                'signed' => $manifest->latestVersion,
            ]);

            return;
        }

        ($this->record)($manifest);
    }
}
