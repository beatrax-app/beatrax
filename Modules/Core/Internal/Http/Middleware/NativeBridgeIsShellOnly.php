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
 * @link ../../../../../.docs/features/desktop/architecture.md
 */
final readonly class NativeBridgeIsShellOnly
{
    private const string BRIDGE_PREFIX = '_native/';

    private const string SECRET_HEADER = 'X-NativePHP-Secret';

    private const string SECRET_COOKIE = '_php_native';

    public function __construct(private Repository $config) {}

    // Answers 404 rather than 403: a caller that cannot show the shell's secret
    // is not being refused a door this deployment has, it is being told there
    // is none -- the same answer every other unowned surface here gives.
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->namesTheBridge($request) && ! $this->carriesTheShellSecret($request)) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function namesTheBridge(Request $request): bool
    {
        return str_starts_with(ltrim($request->path(), '/'), self::BRIDGE_PREFIX);
    }

    // Asked without reference to `running`: a deployment that is not a bundle
    // has no secret at all, so nothing can be presented and the prefix closes
    // on its own. Electron injects the header on every request it originates,
    // which is what the shell has and a browser on the same port does not.
    private function carriesTheShellSecret(Request $request): bool
    {
        $secret = $this->config->get('nativephp-internal.secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = $request->header(self::SECRET_HEADER);
        $cookie = $request->cookie(self::SECRET_COOKIE);

        return (is_string($header) && hash_equals($secret, $header))
            || (is_string($cookie) && hash_equals($secret, $cookie));
    }
}
