<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The NativePHP package registers its bridge routes unconditionally, and the
// guard in front of them returns early when the shell is not running -- so
// outside the shell the bridge is not merely unguarded, it is guarded by
// something that has already decided to let everyone past.
/**
 * @link ../../../../../../.docs/features/desktop/architecture.md
 */
final readonly class NativeBridgeIsShellOnly
{
    private const string BRIDGE_PREFIX = '_native/';

    public function __construct(private Repository $config) {}

    // Answers 404 rather than 403: outside the shell the bridge is not a door
    // this deployment refuses to open, it is one it does not have.
    public function handle(Request $request, Closure $next): Response
    {
        $isBridge = str_starts_with(ltrim($request->path(), '/'), self::BRIDGE_PREFIX);

        if ($isBridge && $this->config->get('nativephp-internal.running') !== true) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
