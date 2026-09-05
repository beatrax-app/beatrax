<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Modules\Core\Internal\Support\NetworkAddress;
use Modules\Core\Internal\Support\NetworkBoundary;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LoopbackOnly
{
    // The SAPI is a parameter so the mobile path can be exercised: PHP_SAPI is
    // a compile-time constant, and a gate that cannot be tested off its own
    // SAPI is how this one shipped able to 404 an entire platform.
    public function __construct(
        private Application $app,
        private NetworkBoundary $boundary,
        private string $sapi = \PHP_SAPI,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->reaches($request)) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // The interface the connection arrived on is the whole answer wherever the
    // SAPI publishes one: loopback always, and past that only an address the
    // install has recorded itself as serving. Absent that record every
    // non-loopback interface is refused, which is the shipped default.
    private function reaches(Request $request): bool
    {
        $serverAddr = $request->server('SERVER_ADDR');

        if (is_string($serverAddr)) {
            return $this->boundary->serves($serverAddr);
        }

        // A real HTTP SAPI with no SERVER_ADDR never advertised its bind
        // address; fail closed rather than assume it was loopback.
        return $this->app->runningInConsole() || $this->servesLocally($request);
    }

    // A SAPI that publishes no bind address is not automatically local. The
    // built-in server and FrankenPHP both bind a real socket while naming no
    // interface, so they answer for the peer they are talking to — and, once
    // the install is widened, for the host it recorded itself under.
    private function servesLocally(Request $request): bool
    {
        $sapi = PhpSapi::tryFrom($this->sapi);

        if ($sapi === null) {
            return false;
        }

        return $sapi->cannotBeReachedOffDevice()
            || $this->peerIsLocal($request)
            || $this->boundary->servesUnderRecordedHost($request->getHost());
    }

    // REMOTE_ADDR is the TCP peer the kernel saw, never a header a caller can
    // choose, so a loopback value means the request never crossed a network —
    // whatever interface the SAPI declined to name.
    private function peerIsLocal(Request $request): bool
    {
        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote) && NetworkAddress::isLoopback($remote);
    }
}
