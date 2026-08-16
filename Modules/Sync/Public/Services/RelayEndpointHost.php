<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Modules\Sync\Internal\Transport\Relay\RelayConfig;

// The host portion of this device's configured relay endpoint. Public because
// Mobile needs the peer's address for a LAN sync dial while RelayConfig stays
// Internal to Sync. Only the host crosses — never the token or the pin.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class RelayEndpointHost
{
    public function __construct(
        private RelayConfig $config,
    ) {}

    public function host(): ?string
    {
        $endpoint = $this->config->endpointUrl();

        if ($endpoint === null) {
            return null;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
