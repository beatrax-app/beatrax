<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Public\Services\AppLockKeyService;

/**
 * Accepts a client-side lock-engage signal and marks the session as locked.
 *
 * Called by lock.js via navigator.sendBeacon (or fetch with keepalive:true) in
 * two scenarios:
 *   1. The 30-second grace window expires after the tab is backgrounded (D-18).
 *      The veil is already showing; this call makes the server session authoritative
 *      so the next full-page request or Livewire update redirects to /lock (D-17).
 *   2. The client-side idle ticker concludes the idle threshold has elapsed and no
 *      Livewire component is listening for 'idle-timeout-elapsed' (e.g. on an app page
 *      that is not the lock screen). Posting here is the reliable path (Gap A fix).
 *
 * The route is inside the auth middleware group, so only authenticated users can
 * reach it. It is NOT in AppLockMiddleware::ALLOWED_ROUTE_NAMES because a locked
 * session should not need to engage again — but calling it while already locked is
 * a harmless no-op (withhold() is idempotent).
 *
 * Returns 204 No Content so the beacon sender does not need to parse a body.
 */
final readonly class LockEngageController
{
    public function __construct(
        private AppLockKeyService $keyService,
    ) {}

    public function __invoke(Session $session): Response
    {
        $this->keyService->withhold($session);

        return new Response('', 204);
    }
}
