<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

// Whether a code this device shows could be answered by the device that scans
// it. A scanner answers by opening a connection to the shower, or by leaving
// the frame with a relay the payload names; only one side of a pairing listens
// and no phone does, so a phone answers only through a relay.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-phone-can-only-be-scanned
 */
class PairingAnswerability
{
    public function __construct(private readonly RelayConfig $relay) {}

    public function canBeAnswered(): bool
    {
        return ! UserDataPathService::isMobileRuntime() || $this->relay->endpointUrl() !== null;
    }
}
