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

    // What Chromium puts on everything a browsing context originates and what
    // the shell's own post -- axios, from the Electron main process -- carries
    // neither of. Either one present means a page issued this, whatever
    // credential it came with.
    /** @var list<string> */
    private const array BROWSING_CONTEXT_HEADERS = ['Sec-Fetch-Site', 'Origin'];

    public function __construct(private Repository $config) {}

    // Answers 404 rather than 403: a caller that cannot show the shell's secret
    // is not being refused a door this deployment has, it is being told there
    // is none -- the same answer every other unowned surface here gives.
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->namesTheBridge($request) && ! $this->isTheShellItself($request)) {
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

    // The secret alone answers the wrong question. Electron stamps it onto
    // every request ADDRESSED to the PHP port, whoever issued it, so a page the
    // window is sitting on -- a bank's consent screen, which is where the
    // open-banking flow sends it -- presents exactly what the shell presents.
    private function isTheShellItself(Request $request): bool
    {
        return ! $this->issuedByAPage($request) && $this->carriesTheShellSecret($request);
    }

    private function issuedByAPage(Request $request): bool
    {
        foreach (self::BROWSING_CONTEXT_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }

    // Asked without reference to `running`: a deployment that is not a bundle
    // has no secret at all, so nothing can be presented and the prefix closes
    // on its own. The header only: the `_php_native` cookie reaches this from a
    // browsing context or from nowhere, and those are refused above.
    private function carriesTheShellSecret(Request $request): bool
    {
        $secret = $this->config->get('nativephp-internal.secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = $request->header(self::SECRET_HEADER);

        return is_string($header) && hash_equals($secret, $header);
    }
}
