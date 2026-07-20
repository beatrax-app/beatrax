<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class GdkEpochDeliveryGateway
{
    public function __construct(
        private readonly GdkEpochControlHandler $handler,
    ) {}

    // Never throws on a malformed/foreign/tampered blob — handle() logs and
    // returns. SECURITY PRECONDITION: $json MUST have arrived over a
    // channel that has already authenticated the sender as a CONFIRMED
    // peer — never wire this to an unauthenticated source.
    public function receiveEpochWrap(string $json, int $userId, Session $session): void
    {
        $this->handler->handle($json, $userId, $session);
    }
}
