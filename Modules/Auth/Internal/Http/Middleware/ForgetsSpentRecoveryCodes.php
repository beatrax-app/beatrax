<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../../.docs/features/auth/pending-recovery-codes-lifetime.md
 */
final readonly class ForgetsSpentRecoveryCodes
{
    // The router's route, not the request: a Livewire update re-runs this
    // against a synthesised request that looks like a plain GET.
    private const string LIVEWIRE_UPDATE_ROUTE = '*livewire.update';

    public function __construct(private Router $routes) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $request->hasSession()) {
            return $response;
        }

        $session = $request->session();
        $renewed = PendingRecoveryCodes::consumeRenewal($session);

        // A Livewire update is always made from a page the reader is already
        // on, so it cannot be the request that left the ceremony — and a poll
        // belonging to some other component on the same page would otherwise
        // take the codes out from under a screen still showing them.
        if ($renewed || $this->routes->currentRouteNamed(self::LIVEWIRE_UPDATE_ROUTE)) {
            return $response;
        }

        PendingRecoveryCodes::forget($session);

        return $response;
    }
}
