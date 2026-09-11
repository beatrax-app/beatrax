<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http;

use Illuminate\Http\Request;
use WeakReference;

// How much of one request the work deferred to terminate() may spend. Shared by
// every AfterResponseMiddleware of a request rather than handed out per
// middleware, because what it bounds — the wait before the WebView is given
// anything to draw — is one quantity however many tails contribute to it.
/**
 * @link ../../../../.docs/features/mobile/a-tail-the-reader-waits-for.md
 */
final class ResponseTailBudget
{
    /** @var WeakReference<Request>|null */
    private ?WeakReference $openFor = null;

    private int $deadline = 0;

    public function __construct(private readonly int $milliseconds) {}

    // Identity, not a count or an id: spl_object_id is reused the moment the
    // previous request is collected, and this object outlives every request in
    // a persistent runtime. A weak reference cannot keep one alive to answer.
    public function openFor(Request $request): void
    {
        if ($this->openFor?->get() === $request) {
            return;
        }

        $this->openFor = WeakReference::create($request);
        $this->deadline = hrtime(true) + $this->milliseconds * 1_000_000;
    }

    // False outside a request tail, so a console, a queue worker or a test
    // calling the same work directly is bounded by whatever bounds it there.
    // Callers ask it BETWEEN units and never before the first: a tail that can
    // be denied its first unit makes no progress on the request after it either.
    public function spent(): bool
    {
        return $this->openFor !== null && hrtime(true) >= $this->deadline;
    }
}
