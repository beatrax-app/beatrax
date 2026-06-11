<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Public\Services\AppLockKeyService;

/**
 * Accepts a client-side lock-engage signal and marks the session as locked.
 *
 * Called by lock.js via fetch with keepalive:true (NOT navigator.sendBeacon:
 * Laravel's VerifyCsrfToken only accepts the token from a `_token` body field
 * or the X-CSRF-TOKEN / X-XSRF-TOKEN headers, and sendBeacon cannot set
 * headers — a beacon would always 419) in two scenarios:
 *   1. The 30-second grace window expires after the tab is backgrounded (D-18).
 *      The veil is already showing; this call makes the server session authoritative
 *      so the next full-page request or Livewire update redirects to /lock (D-17).
 *   2. The client-side idle ticker concludes the idle threshold has elapsed
 *      on an app page. Posting here is the reliable path (Gap A fix).
 *
 * The route is inside the auth middleware group, so only authenticated users can
 * reach it. It IS in AppLockMiddleware::ALLOWED_ROUTE_NAMES so a request that
 * races an already-locked session is not redirected; calling it while already
 * locked is a harmless no-op (withhold() is idempotent).
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
