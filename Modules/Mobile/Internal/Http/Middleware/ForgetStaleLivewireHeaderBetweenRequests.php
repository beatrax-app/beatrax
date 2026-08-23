<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Livewire\LivewireManager;
use Symfony\Component\HttpFoundation\Response;

// Livewire decides two things by asking whether X-Livewire is on the request,
// and on this persistent runtime an ordinary page load carries one left behind
// by an earlier component update.
/**
 * @link ../../../../../.docs/conventions/invariants-from-shipped-failures.md#a-stale-x-livewire-header-on-a-page-load
 */
final class ForgetStaleLivewireHeaderBetweenRequests
{
    private const string HEADER = 'X-Livewire';

    public function __construct(private readonly LivewireManager $livewire) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isUpdateEndpoint($request)) {
            $request->headers->remove(self::HEADER);
            $request->server->remove('HTTP_X_LIVEWIRE');
        }

        return $next($request);
    }

    // By path rather than by route name: this runs ahead of routing, and the
    // endpoint's own URI is what Livewire itself matches on.
    private function isUpdateEndpoint(Request $request): bool
    {
        $updateUri = $this->livewire->getUpdateUri();

        return is_string($updateUri)
            && '/'.ltrim($request->path(), '/') === '/'.ltrim($updateUri, '/');
    }
}
