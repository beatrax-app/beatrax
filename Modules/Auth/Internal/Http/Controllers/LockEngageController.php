<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Public\Services\AppLockKeyService;

// lock.js posts here via fetch with keepalive:true, not
// navigator.sendBeacon: sendBeacon cannot set headers, and
// VerifyCsrfToken only accepts the token from a _token body field or
// the X-CSRF-TOKEN/X-XSRF-TOKEN headers -- a beacon would always 419.
final readonly class LockEngageController
{
    public function __construct(
        private AppLockKeyService $keyService,
    ) {}

    public function __invoke(Session $session): Response
    {
        // Idempotent: withhold() on an already-locked session is a
        // harmless no-op, so a request racing an already-locked session
        // (this route is allow-listed in AppLockMiddleware) is safe.
        $this->keyService->withhold($session);

        return new Response('', 204);
    }
}
