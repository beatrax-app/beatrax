<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;

/**
 * Narrow Public seam letting a non-Sync module's OWN authenticated LAN
 * session receive leg route a decrypted `GDK_EPOCH_WRAP` control-message
 * blob through `GdkEpochControlHandler::handle()` — without reaching into
 * `Modules\Sync\Internal\*` directly (CLAUDE.md modular-architecture
 * constraint; enforced by `App\PhpStan\Rules\BoundaryRule`, which has no
 * per-file ignore mechanism).
 *
 * Added for Phase 15 import-join (Task 4, G3): `Modules\Mobile\Internal\
 * Sync\LanSyncClient`'s post-catch-up receive step needs the EXACT same
 * validate-open-append boundary `SyncWebSocketHandler::deliverGdkEpochWraps()`
 * already uses on the desktop side. This gateway is that one seam — a thin
 * pass-through. NO new trust decision is introduced: `handle()` itself
 * remains the SOLE point where a wrap is opened and appended to the local
 * keyring, and its documented WR-07 authenticated-channel precondition
 * (this method must only ever be called with a blob that arrived over an
 * already-authenticated, confirmed-peer session) is unchanged and still the
 * caller's responsibility.
 */
final class GdkEpochDeliveryGateway
{
    public function __construct(
        private readonly GdkEpochControlHandler $handler,
    ) {}

    /**
     * Route one decrypted `GDK_EPOCH_WRAP` control-message JSON blob through
     * the receive-side validate/open/append boundary. Never throws on a
     * malformed/foreign/tampered blob — `handle()` logs and returns.
     *
     * SECURITY PRECONDITION (WR-07, unchanged): $json MUST have arrived over
     * a channel that has already authenticated the sender as a CONFIRMED
     * peer (in `LanSyncClient`'s case, the live Noise IK session that just
     * completed catch-up authentication). Never wire this to an
     * unauthenticated source (e.g. a raw relay-pushed, un-drained blob).
     */
    public function receiveEpochWrap(string $json, int $userId, Session $session): void
    {
        $this->handler->handle($json, $userId, $session);
    }
}
