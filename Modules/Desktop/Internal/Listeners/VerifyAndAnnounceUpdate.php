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
 * @link ../../../../.docs/features/desktop/auto-update.md
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
        // The mobile runtime must never act on a desktop binary update — the app
        // stores own that path — so it is refused here as well as at registration.
        if (UserDataPathService::isMobileRuntime()) {
            return;
        }

        $manifest = $this->channel->poll($this->fetcher);
        if ($manifest === null) {
            return;
        }

        // A feed serving a signed manifest for one version and a binary for another
        // must never reach the banner the user consents from.
        if ($manifest->latestVersion !== $event->version) {
            $this->logger->warning('VerifyAndAnnounceUpdate: signed manifest version disagrees with the update offered.', [
                'offered' => $event->version,
                'signed' => $manifest->latestVersion,
            ]);

            return;
        }

        ($this->record)(
            $manifest,
            $this->channel->alertKindFor($manifest),
            $this->channel->installedVersion(),
        );
    }
}
