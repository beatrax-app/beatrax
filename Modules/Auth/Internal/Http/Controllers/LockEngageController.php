<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Internal\Actions\EngageAppLock;

// lock.js posts here with keepalive, not sendBeacon: a beacon cannot set
// headers, so VerifyCsrfToken would 419 it every time. 204 on every path,
// including the ones the action declines to act on — the browser half reads
// the status and nothing else.
final readonly class LockEngageController
{
    public function __construct(
        private EngageAppLock $engage,
    ) {}

    public function __invoke(Session $session): Response
    {
        ($this->engage)($session);

        return new Response('', 204);
    }
}
