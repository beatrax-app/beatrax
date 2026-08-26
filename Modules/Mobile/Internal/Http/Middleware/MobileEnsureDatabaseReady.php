<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Symfony\Component\HttpFoundation\Response;

final class MobileEnsureDatabaseReady
{
    // Every route here runs before any user account exists on this
    // device (the welcome/signup/import/pair/setup chain), or is a public
    // artifact (webmanifest/icon) required before any user exists - see
    // .docs/features/mobile/architecture.md.
    /** @var array<int, string> */
    private const EXEMPT_ROUTE_PREFIXES = [
        'mobile.welcome',
        'signup',
        'mobile.import',
        // The route back from a wipe. It exists to be reached when there is
        // no user, which is exactly the state this gate redirects out of.
        'mobile.restore',
        'mobile.pair',
        'mobile.setup',
        'setup',
        'site.webmanifest',
        'pwa.icon',
        // Static brand artefacts, served by PHP because no web server sits in
        // front of it here. The desktop setup, staging and welcome shells
        // embed the app mark, and this gate is what shows them.
        'app.icon',
        'app.splash',
        'locale.switch',
    ];

    // The Livewire AJAX update endpoint must reach the signup form's
    // submit() handler on a fresh install.
    /** @var array<int, string> */
    private const EXEMPT_ROUTE_SUFFIXES = [
        'livewire.update',
        // The restore form's file input posts here. Redirected, it answers
        // with the welcome page at 200 -- iOS turns a redirect into the
        // target's HTML -- and Livewire's JS parses a document as JSON, throws,
        // and never settles the upload. The screen said "Uploading..." forever.
        'livewire.upload-file',
        'livewire.preview-file',
    ];

    public function __construct(
        private readonly MobileFirstLaunchBootstrap $bootstrap,
        private readonly UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if ($this->bootstrap->isFreshInstall()) {
            return new RedirectResponse($this->urls->route('mobile.welcome'));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();
        if (! is_string($name)) {
            return false;
        }

        return $this->matchesExemptPrefix($name) || $this->matchesExemptSuffix($name);
    }

    private function matchesExemptPrefix(string $name): bool
    {
        foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function matchesExemptSuffix(string $name): bool
    {
        foreach (self::EXEMPT_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
