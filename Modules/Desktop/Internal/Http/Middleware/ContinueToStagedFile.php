<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Desktop\Internal\Native\PendingFileIntent;
use Symfony\Component\HttpFoundation\Response;

// The step between remembering an OS file-open and showing it. Nothing else
// navigates to desktop.file-staging, so a double-clicked bank export was
// validated, stored and then answered with the dashboard.
final readonly class ContinueToStagedFile
{
    private const string STAGING_ROUTE = 'desktop.file-staging';

    public function __construct(
        private PendingFileIntent $intent,
        private CurrentUser $currentUser,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldContinue($request)) {
            return new RedirectResponse($this->urls->route(self::STAGING_ROUTE));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // Authentication is checked before the intent, not after: the staging route
    // is behind `auth`, so redirecting a signed-out reader there bounces them
    // back to the login screen this middleware would redirect again.
    private function shouldContinue(Request $request): bool
    {
        return $request->isMethod('GET')
            && $request->acceptsHtml()
            && ! $request->routeIs(self::STAGING_ROUTE)
            && $this->currentUser->isAuthenticated()
            && $this->intent->pending() !== null;
    }
}
