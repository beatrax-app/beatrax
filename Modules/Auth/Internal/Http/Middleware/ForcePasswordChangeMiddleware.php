<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

final readonly class ForcePasswordChangeMiddleware
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_ROUTE_NAMES = ['auth.change-password', 'logout'];

    // The one exempt page renders inside layouts.app, which mounts nine further
    // components beside the password form -- the ledger search endpoint, the
    // rule form and the community publisher among them. A Livewire update names
    // the component it drives, so the exemption is taken from that instead.
    /**
     * @var list<string>
     */
    private const array ALLOWED_LIVEWIRE_COMPONENTS = [
        'auth.change-password-page',
        // Owns no action at all and polls on a developer's screen, so refusing
        // it withholds nothing and reloads the page every five seconds.
        'core.app-sidebar',
    ];

    private const string LIVEWIRE_UPDATE_ROUTE = '*livewire.update';

    public function __construct(
        private CurrentUser $currentUser,
        private UrlGenerator $urls,
        private Router $routes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            $user = $this->currentUser->user();

            if ($user->force_password_change_at_next_login && ! $this->isExempt($request)) {
                return new RedirectResponse($this->urls->route('auth.change-password'));
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        if (! in_array($request->route()?->getName(), self::ALLOWED_ROUTE_NAMES, true)) {
            return false;
        }

        // The router's route, not the request's: Livewire re-runs this against a
        // synthesised request wearing the original page's route, while the
        // router still holds the endpoint the payload really arrived at.
        if (! $this->routes->currentRouteNamed(self::LIVEWIRE_UPDATE_ROUTE)) {
            return true;
        }

        $components = $this->livewireComponentNames($request);

        return $components !== [] && array_diff($components, self::ALLOWED_LIVEWIRE_COMPONENTS) === [];
    }

    // A batch is exempt only if every component in it is, and an unreadable
    // payload answers with the empty list, which refuses the whole request.
    /**
     * @return list<string>
     */
    private function livewireComponentNames(Request $request): array
    {
        $payload = $request->input('components');

        if (! is_array($payload) || $payload === []) {
            return [];
        }

        $names = [];

        foreach ($payload as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;
            /** @var mixed $decoded */
            $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $memo = is_array($decoded) ? ($decoded['memo'] ?? null) : null;
            $name = is_array($memo) ? ($memo['name'] ?? null) : null;

            if (! is_string($name)) {
                return [];
            }

            $names[] = $name;
        }

        return $names;
    }
}
