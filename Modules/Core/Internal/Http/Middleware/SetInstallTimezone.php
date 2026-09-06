<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Services\InstallTimezone;
use Symfony\Component\HttpFoundation\Response;

// Binds the installation's zone onto the process before the route renders.
// It runs on every request rather than once at boot because the choice is a
// stored row: a reader who changes it in settings must not have to restart
// the application to see the day boundary move.
final readonly class SetInstallTimezone
{
    public function __construct(private InstallTimezone $timezone) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->timezone->apply($this->timezone->zone());

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
