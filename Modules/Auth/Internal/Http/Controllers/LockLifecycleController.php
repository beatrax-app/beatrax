<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Auth\Internal\Actions\RecordAppBackgrounded;

// The two ends of a backgrounding. lock.js's own 30s timer cannot be trusted
// on mobile: a suspended WebView never fires it and the return handler then
// cancelled it, so the phone came back unlocked.
final readonly class LockLifecycleController
{
    public function __construct(
        private RecordAppBackgrounded $recordBackgrounded,
    ) {}

    public function background(Session $session): Response
    {
        ($this->recordBackgrounded)($session);

        return new Response('', 204);
    }

    /**
     * @link ../../../../../.docs/features/auth/architecture.md#why-the-mobile-runtime-forced-two-changes-here
     */
    // Reaching this body means the middleware judged the return within grace.
    // A body, not a status or redirect: the Android bridge rewrites both.
    public function resume(): JsonResponse
    {
        return new JsonResponse(['locked' => false]);
    }
}
